<?php

use Illuminate\Support\Facades\Route;
use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;
use App\Models\Product;
use App\Models\Appointment;
use App\Models\CallContext;
use App\Services\AvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\AIAssistant;

// Voice: llamada entrante - Seleccionar familia por voz o DTMF
Route::match(['get', 'post'], '/voice/incoming', function () {
    $callSid = request('CallSid');
    $from = request('From');
    
    Log::info('📞 [INCOMING CALL]', [
        'call_sid' => $callSid,
        'from' => $from,
        'timestamp' => now()->toIso8601String()
    ]);
    
    if (!$callSid) {
        $response = new VoiceResponse();
        $response->say('No se recibió identificador de llamada.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->hangup();
        return response($response, 400)->header('Content-Type', 'text/xml');
    }

    $context = CallContext::firstOrCreate(
        ['call_sid' => $callSid],
        ['customer_phone' => $from, 'step' => 'welcome']
    );
    
    $response = new VoiceResponse();
    $gather = $response->gather([
        'input' => 'speech dtmf',
        'language' => 'es-ES',
        'speechModel' => 'phone_call',
        'hints' => 'hola, cita, precio, facial, manos, preguntar, información, promociones, agendar, 1, 2',
        'action' => url('/voice/gather'),
        'timeout' => 10,
        'speechTimeout' => 'auto'
    ]);
    $gather->say('Hola, bienvenido a Estética Contemporánea, ¿en qué puedo ayudarte?', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->say('No escuché respuesta, intentaré de nuevo.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->redirect(url('/voice/incoming'));
    return response($response)->header('Content-Type', 'text/xml');
});

// Procesar selección de familia
Route::match(['get', 'post'], '/voice/gather', function () {
    $callSid = request('CallSid');
    $digit = request('Digits');
    $speechResult = strtolower(trim(request('SpeechResult') ?? ''));

    Log::info('📝 [SPEECH TRACE]', [
        'call_sid' => $callSid,
        'speech_raw' => request('SpeechResult'),
        'speech_normalized' => $speechResult,
        'confidence' => request('Confidence'),
        'speech_language' => request('SpeechLanguage'),
        'dtmf' => $digit,
        'timestamp' => now()->toIso8601String(),
    ]);
    
    Log::info('🎙️ [GATHER INPUT]', [
        'call_sid' => $callSid,
        'digits' => $digit,
        'speech' => $speechResult,
        'confidence' => request('Confidence')
    ]);
    
    $context = CallContext::where('call_sid', $callSid)->first();
    $response = new VoiceResponse();
    
    // Procesar entrada (voz o DTMF)
    $family = null;
    if ($digit === '0' || $speechResult === 'repetir') {
        $response->redirect(url('/voice/incoming'));
        return response($response)->header('Content-Type', 'text/xml');
    } elLog::warning('⚠️ [INVALID FAMILY]', ['call_sid' => $callSid, 'input' => $speechResult ?: $digit]);
        $response->say('No entendí su selección. Por favor intente de nuevo.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/incoming'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    Log::info('✅ [FAMILY SELECTED]', ['call_sid' => $callSid, 'family' => $family]);}

    if (!$family) {
        $response->say('No entendí su selección. Por favor intente de nuevo.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/incoming'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    $context->update(['family' => $family, 'step' => 'selecting_product']);

    $products = Product::where('family', $family)->where('active', true)->get();
    if ($products->isEmpty()) {
        $response->say('No hay servicios disponibles en esta familia.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->hangup();
        return response($response)->header('Content-Type', 'text/xml');
    }

    $response->say('Servicios disponibles:', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    foreach ($products as $index => $p) {
        $priceEuros = number_format($p->price_cents / 100, 2);
        $response->say(
            ($index + 1) . '. ' . $p->name . ', duración ' . $p->duration_minutes . ' minutos, precio ' . $priceEuros . ' euros.', 
            ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
        );
    }
    $gather = $response->gather([
        'input' => 'speech dtmf',
        'hints' => implode(', ', $products->pluck('name')->toArray()) . ', volver',
        'action' => url('/voice/product'),
        'timeout' => 5,
        'speechTimeout' => 'auto'
    ]);
    $gather->say('Diga el nombre del servicio o presione el número correspondiente. O diga volver para regresar.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->redirect(url('/voice/incoming'));
    return response($response)->header('Content-Type', 'text/xml');
});

// Elegir producto por voz o número
Route::match(['get', 'post'], '/voice/product', function () {
    $callSid = request('CallSid');
    $digit = request('Digits');
    $speechResult = strtolower(trim(request('SpeechResult') ?? ''));
    
    Log::info('🛍️ [PRODUCT SELECTION]', [
        'call_sid' => $callSid,
        'digits' => $digit,
        'speech' => $speechResult
    ]);
    
    $context = CallContext::where('call_sid', $callSid)->first();
    $response = new VoiceResponse();
    
    if ($digit === '0' || $speechResult === 'volver') {
        $response->redirect(url('/voice/incoming'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    $products = Product::where('family', $context->family)->where('active', true)->get();
    
    // Buscar por número o por nombre de voz
    $product = null;
    if (!empty($digit)) {
        $productIndex = ((int)$digit) - 1;
        if ($productIndex >= 0 && $productIndex < $products->count()) {
            $product = $products->get($productIndex);
        }
    } elseif (!empty($speechResult)) {
        foreach ($products as $p) {
            if (strpos($speechResult, strtolower($p->name)) !== false) {
                $product = $p;
        Log::warning('⚠️ [INVALID PRODUCT]', ['call_sid' => $callSid, 'input' => $speechResult ?: $digit]);
        $response->say('No entendí su selección. Por favor intente de nuevo.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/gather'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    Log::info('✅ [PRODUCT CONFIRMED]', [
        'call_sid' => $callSid,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'price' => $product->price_cents / 100,
        'duration' => $product->duration_minutes
    ]);
    if (!$product) {
        $response->say('No entendí su selección. Por favor intente de nuevo.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/gather'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    $context->update(['product_id' => $product->id, 'step' => 'requesting_datetime']);

    $response->say('Ha elegido ' . $product->name . '.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->say('Para agendar su cita, indique la fecha deseada.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->say('Puede decir la fecha, por ejemplo 20 de Diciembre, o marcar los dígitos 2, 0, 1, 2.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    
    $gather = $response->gather([
        'input' => 'speech dtmf',
        'hints' => 'próximo disponible, hoy, mañana, 9',
        'action' => url('/voice/date'),
        'timeout' => 10,
        'speechTimeout' => 'auto'
    ]);
    $gather->say('Diga la fecha o marque los dígitos del día y mes, o presione 9 para el próximo día disponible.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->redirect(url('/voice/product'));
    return response($response)->header('Content-Type', 'text/xml');
});Log::info('📅 [DATE REQUEST]', [
        'call_sid' => $callSid,
        'digits' => $digits,
        'speech' => $speechResult
    ]);
    
    

// Procesar fecha
Route::match(['get', 'post'], '/voice/date', function () {
    $callSid = request('CallSid');
    $digits = request('Digits');
    $speechResult = strtolower(trim(request('SpeechResult') ?? ''));
    
    $context = CallContext::where('call_sid', $callSid)->first();
    $response = new VoiceResponse();
    
    // Si presiona 9, usar próximo día disponible
    if ($digits === '9' || strpos($speechResult, 'próximo') !== false) {
        Log::info('🔍 [NEXT AVAILABLE SLOT]', ['call_sid' => $callSid]);
        $availabilityService = new AvailabilityService();
        $product = $context->product;
        $nextSlot = $availabilityService->findNextAvailableSlot(Carbon::now(), $product->duration_minutes);
        
        if (!$nextSlot) {
            Log::warning('❌ [NO AVAILABILITY]', ['call_sid' => $callSid, 'days_checked' => 30]);
            $response->say('Lo sentimos, no hay disponibilidad en los próximos 30 días.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->say('Por favor contacte con nosotros directamente.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->hangup();
            return response($response)->header('Content-Type', 'text/xml');
        }
        
        $appointment = Appointment::create([
            'customer_name' => 'Cliente',
            'customer_phone' => $context->customer_phone,
            'product_id' => $context->product_id,
            'starts_at' => $nextSlot['start'],
            'ends_at' => $nextSlot['end'],
            'status' => 'scheduled',
        ]);
        
        Log::info('✅ [APPOINTMENT CREATED]', [
            'call_sid' => $callSid,
            'appointment_id' => $appointment->id,
            'customer_phone' => $context->customer_phone,
            'product_id' => $context->product_id,
            'product_name' => $product->name,
            'starts_at' => $nextSlot['start']->toIso8601String(),
            'ends_at' => $nextSlot['end']->toIso8601String(),
            'auto_scheduled' => true
        ]);
        
        $context->delete();
        
        $response->say(
            'Su cita ha sido agendada para el ' . 
            $nextSlot['start']->locale('es')->isoFormat('D [de] MMMM [a las] H:mm') . '.', 
            ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
        );
        $response->say('Recibirá una confirmación por SMS. Gracias por su confianza.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->hangup();
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    // Parsear fecha desde DTMF o voz
    $requestedDate = null;
    
    if (!empty($digits) && strlen($digits) === 4) {
        $day = (int)substr($digits, 0, 2);
        $month = (int)substr($digits, 2, 2);
        $year = Carbon::now()->year;
        try {
            $requestedDate = Carbon::create($year, $month, $day);
            if ($requestedDate->isPast()) $requestedDate->addYear();
        } catch (\Exception $e) {
            $requestedDate = null;
        }
    } elseif (!empty($speechResult)) {
        // Parsear fechas habladas: "20 de Diciembre", "hoy", "mañana"
        if ($speechResult === 'hoy') {
            $requestedDate = Carbon::now();
        } elseif ($speechResult === 'mañana') {
            $requestedDate = Carbon::tomorrow();
        } else {
            // Intentar extraer día y mes
            preg_match('/(\d+)/', $speechResult, $dayMatch);
            $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 
                      'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
            $month = null;
            foreach ($meses as $mes => $num) {
                if (strpos($speechResult, $mes) !== false) {
                    $month = $num;
                    break;
                }
            }
            if (!empty($dayMatch) && $month) {
                try {
                    $day = (int)$dayMatch[1];
                    $year = Carbon::now()->year;
                    $requestedDate = Carbon::create($year, $month, $day);
                    if ($requestedDate->isPast()) $requestedDate->addYear();
                } catch (\Exception $e) {
                    $requestedDate = null;
                }
            }
        }
    }
    
    if (!$requestedDate) {
        Log::warning('⚠️ [INVALID DATE]', ['call_sid' => $callSid, 'input' => $speechResult ?: $digits]);
        $response->say('No entendí la fecha. Por favor intente nuevamente.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/product'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    Log::info('📅 [DATE PARSED]', [
        'call_sid' => $callSid,
        'date' => $requestedDate->toDateString(),
        'day_name' => $requestedDate->locale('es')->dayName
    ]);
    
    // Validar día laboral
    if ($requestedDate->isWeekend()) {
        Log::warning('⚠️ [WEEKEND DATE]', ['call_sid' => $callSid, 'date' => $requestedDate->toDateString()]);
        $response->say('La fecha seleccionada cae en fin de semana. No trabajamos sábados ni domingos.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->say('Por favor diga otra fecha.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/product'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    $context->update(['requested_date' => $requestedDate->toDateString()]);
    
    $response->say(
        'Fecha: ' . $requestedDate->locale('es')->isoFormat('D [de] MMMM [de] YYYY') . '.', 
        ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
    );
    $response->say('Ahora dígame la hora deseada o marque 4 dígitos en formato 24 horas.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->say('Por ejemplo, puede decir las 3 y media de la tarde, o marcar 1, 5, 3, 0 para las 15:30.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    
    $gather = $response->gather([
        'input' => 'speech dtmf',
        'action' => url('/voice/confirm'),
        'timeout' => 10,
        'speechTimeout' => 'auto'
    ]);
    $gather->say('Diga la hora o marque 4 dígitos.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->redirect(url('/voice/date'));
    
    return response($response)->header('Content-Type', 'text/xml');
});

// Confirmar y registrar cita
Route::match(['get', 'post'], '/voice/confirm', function () {
    $callSid = request('CallSid');
    $digits = request('Digits');
    $speechResult = strtolower(trim(request('SpeechResult') ?? ''));
    
    Log::info('⏰ [TIME REQUEST]', [
        'call_sid' => $callSid,
        'digits' => $digits,
        'speech' => $speechResult
    ]);
    
    $context = CallContext::where('call_sid', $callSid)->first();
    $response = new VoiceResponse();
    
    $hour = null;
    $minute = null;
    
    // Parsear hora desde DTMF (formato HHMM)
    if (!empty($digits) && strlen($digits) === 4) {
        $hour = (int)substr($digits, 0, 2);
        $minute = (int)substr($digits, 2, 2);
    } elseif (!empty($speechResult)) {
        // Parsear hora desde voz (ej: "3 y media", "15 horas", "las 3 de la tarde")
        preg_match('/\d+/', $speechResult, $matches);
        if (!empty($matches)) {
            $hour = (int)$matches[0];
            // Detectar minutos
            if (strpos($speechResult, 'media') !== false || strpos($speechResult, '30') !== false) {
                $minute = 30;
            } elseif (strpos($speechResult, 'cuarto') !== false || strpos($speechResult, '15') !== false) {
                $minute = 15;
            } else {
                $minute = 0;
            }
            // Ajustar por AM/PM
            if (strpos($speechResult, 'tarde') !== false && $hour < 12) {
                $hour += 12;
            } elseif (strpos($speechResult, 'mañana') !== false && $hour >= 12) {
                $hour -= 12;
            }
        }
    }
    
    if ($hour === null || $minute === null || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
        Log::warning('⚠️ [INVALID TIME]', ['call_sid' => $callSid, 'hour' => $hour, 'minute' => $minute]);
        $response->say('No entendí la hora o es inválida. Por favor intente nuevamente.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/date'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    Log::info('⏰ [TIME PARSED]', [
        'call_sid' => $callSid,
        'hour' => $hour,
        'minute' => $minute,
        'formatted' => sprintf('%02d:%02d', $hour, $minute)
    ]);
    
    try {
        $product = $context->product;
        $requestedDateTime = Carbon::parse($context->requested_date)->setTime($hour, $minute);
        $endDateTime = $requestedDateTime->copy()->addMinutes($product->duration_minutes);
        
        // Validar horario de trabajo
        if ($hour < 9 || $hour >= 19) {
            Log::warning('⚠️ [OUT OF HOURS]', ['call_sid' => $callSid, 'requested_hour' => $hour]);
            $response->say('Lo sentimos, nuestro horario es de 9 de la mañana a 7 de la tarde.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->say('Por favor elija una hora dentro del horario laboral.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->redirect(url('/voice/date'));
            return response($response)->header('Content-Type', 'text/xml');
        }
        
        // Verificar disponibilidad
        $availabilityService = new AvailabilityService();
        
        if (!$availabilityService->isSlotAvailable($requestedDateTime, $endDateTime)) {
            Log::warning('❌ [SLOT NOT AVAILABLE]', [
                'call_sid' => $callSid,
                'requested_datetime' => $requestedDateTime->toIso8601String(),
                'duration' => $product->duration_minutes
            ]);
            $response->say('Lo sentimos, ese horario no está disponible.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            
            // Ofrecer alternativas
            $slots = $availabilityService->getAvailableSlots(
                $context->requested_date, 
                $product->duration_minutes
            );
            
            if (!empty($slots)) {
                $response->say('Horarios disponibles para ese día:', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
                foreach (array_slice($slots, 0, 3) as $slot) {
                    $response->say($slot['display'], ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
                }
                $response->say('Por favor elija otro horario.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
                $response->redirect(url('/voice/date'));
            } else {
                $response->say('No hay disponibilidad para ese día. Por favor intente otra fecha.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
                $response->redirect(url('/voice/product'));
            }
        $appointment = Appointment::create([
            'customer_name' => 'Cliente',
            'customer_phone' => $context->customer_phone,
            'product_id' => $context->product_id,
            'starts_at' => $requestedDateTime,
            'ends_at' => $endDateTime,
            'status' => 'scheduled',
        ]);
        
        Log::info('✅ [APPOINTMENT CREATED]', [
            'call_sid' => $callSid,
            'appointment_id' => $appointment->id,
            'customer_phone' => $context->customer_phone,
            'product_id' => $context->product_id,
            'product_name' => $product->name,
            'starts_at' => $requestedDateTime->toIso8601String(),
            'ends_at' => $endDateTime->toIso8601String(),
            'status' => 'scheduled'nte',
            'customer_phone' => $context->customer_phone,
            'product_id' => $context->product_id,
            'starts_at' => $requestedDateTime,
            'ends_at' => $endDateTime,
            'status' => 'scheduled',
        ]);
        
        $context->delete();
        Log::error('❌ [APPOINTMENT ERROR]', [
            'call_sid' => $callSid,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        $response->say(
            'Perfecto. Su cita ha sido confirmada para el ' . 
            $requestedDateTime->locale('es')->isoFormat('D [de] MMMM [a las] H:mm') . '.', 
            ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
        );
        $response->say('El servicio es ' . $product->name . ', con una duración de ' . $product->duration_minutes . ' minutos.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->say('Recibirá una confirmación por SMS. Gracias por confiar en Contemporánea Estética.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->hangup();
        
    } catch (\Exception $e) {
        $response->say('Ha ocurrido un error al procesar su cita. Por favor intente nuevamente o contacte con nosotros.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/incoming'));
    }
    
    return response($response)->header('Content-Type', 'text/xml');
});
