<?php

use Illuminate\Support\Facades\Route;
use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;
use App\Models\Product;
use App\Models\Appointment;
use App\Models\CallContext;
use App\Services\AvailabilityService;
use Illuminate\Support\Carbon;

// Voice: llamada entrante - Seleccionar familia por voz o DTMF
Route::match(['get', 'post'], '/voice/incoming', function () {
    $callSid = request('CallSid');
    $from = request('From');
    
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
    $response->say('Bienvenido a Contemporánea Estética.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $gather = $response->gather([
        'input' => 'speech dtmf',
        'hints' => 'facial, faciales, manos, repetir, 1, 2, 0',
        'action' => url('/voice/gather'),
        'timeout' => 5,
        'speechTimeout' => 'auto'
    ]);
    $gather->say('Diga facial o manos para elegir, o presione los números 1 y 2.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->say('No se detectó elección. Intentando de nuevo.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->redirect(url('/voice/incoming'));
    return response($response)->header('Content-Type', 'text/xml');
});

// Procesar selección de familia
Route::match(['get', 'post'], '/voice/gather', function () {
    $callSid = request('CallSid');
    $digit = request('Digits');
    $speechResult = strtolower(trim(request('SpeechResult') ?? ''));
    
    $context = CallContext::where('call_sid', $callSid)->first();
    $response = new VoiceResponse();
    
    // Procesar entrada (voz o DTMF)
    $family = null;
    if ($digit === '0' || $speechResult === 'repetir') {
        $response->redirect(url('/voice/incoming'));
        return response($response)->header('Content-Type', 'text/xml');
    } elseif ($digit === '1' || strpos($speechResult, 'facial') !== false) {
        $family = 'facial';
    } elseif ($digit === '2' || $speechResult === 'manos') {
        $family = 'manos';
    }

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
                break;
            }
        }
    }
    
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
});

// Procesar fecha
Route::match(['get', 'post'], '/voice/date', function () {
    $callSid = request('CallSid');
    $digits = request('Digits');
    $speechResult = strtolower(trim(request('SpeechResult') ?? ''));
    
    $context = CallContext::where('call_sid', $callSid)->first();
    $response = new VoiceResponse();
    
    // Si presiona 9, usar próximo día disponible
    if ($digits === '9' || strpos($speechResult, 'próximo') !== false) {
        $availabilityService = new AvailabilityService();
        $product = $context->product;
        $nextSlot = $availabilityService->findNextAvailableSlot(Carbon::now(), $product->duration_minutes);
        
        if (!$nextSlot) {
            $response->say('Lo sentimos, no hay disponibilidad en los próximos 30 días.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->say('Por favor contacte con nosotros directamente.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->hangup();
            return response($response)->header('Content-Type', 'text/xml');
        }
        
        Appointment::create([
            'customer_name' => 'Cliente',
            'customer_phone' => $context->customer_phone,
            'product_id' => $context->product_id,
            'starts_at' => $nextSlot['start'],
            'ends_at' => $nextSlot['end'],
            'status' => 'scheduled',
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
        $response->say('No entendí la fecha. Por favor intente nuevamente.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/product'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    // Validar día laboral
    if ($requestedDate->isWeekend()) {
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
        $response->say('No entendí la hora o es inválida. Por favor intente nuevamente.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/date'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    try {
        $product = $context->product;
        $requestedDateTime = Carbon::parse($context->requested_date)->setTime($hour, $minute);
        $endDateTime = $requestedDateTime->copy()->addMinutes($product->duration_minutes);
        
        // Validar horario de trabajo
        if ($hour < 9 || $hour >= 19) {
            $response->say('Lo sentimos, nuestro horario es de 9 de la mañana a 7 de la tarde.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->say('Por favor elija una hora dentro del horario laboral.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->redirect(url('/voice/date'));
            return response($response)->header('Content-Type', 'text/xml');
        }
        
        // Verificar disponibilidad
        $availabilityService = new AvailabilityService();
        
        if (!$availabilityService->isSlotAvailable($requestedDateTime, $endDateTime)) {
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
            
            return response($response)->header('Content-Type', 'text/xml');
        }
        
        // Crear la cita
        Appointment::create([
            'customer_name' => 'Cliente',
            'customer_phone' => $context->customer_phone,
            'product_id' => $context->product_id,
            'starts_at' => $requestedDateTime,
            'ends_at' => $endDateTime,
            'status' => 'scheduled',
        ]);
        
        $context->delete();
        
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
