<?php

use Illuminate\Support\Facades\Route;
use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;
use App\Models\Product;
use App\Models\Appointment;
use App\Models\CallContext;
use App\Services\AvailabilityService;
use App\Services\AIAssistant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

// Ruta de prueba para enviar SMS
Route::get('/twilio-test', function () {
    $account_sid = env('TWILIO_ACCOUNT_SID');
    $auth_token = env('TWILIO_AUTH_TOKEN');
    $twilio_number = env('TWILIO_PHONE_NUMBER');
    
    if (!$account_sid || !$auth_token || !$twilio_number) {
        return response()->json([
            'error' => 'Faltan credenciales de Twilio en .env'
        ], 400);
    }
    
    try {
        $client = new Client($account_sid, $auth_token);
        
        // Enviar SMS de prueba (cambia +34XXXXXXXXX por tu número)
        $message = $client->messages->create(
            '+34XXXXXXXXX', // Número destino
            [
                'from' => $twilio_number,
                'body' => 'Prueba desde Laravel + Twilio ✓'
            ]
        );
        
        return response()->json([
            'status' => 'SMS enviado correctamente',
            'message_sid' => $message->sid
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage()
        ], 500);
    }
});

// Voice: llamada entrante
Route::match(['get', 'post'], '/voice/incoming', function () {
    $callSid = request('CallSid');
    $from = request('From');
    
    // Si falta CallSid (p.ej. prueba manual con curl sin params), responde sin romper
    if (!$callSid) {
        $response = new VoiceResponse();
        $response->say('No se recibió identificador de llamada.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->hangup();
        return response($response, 400)->header('Content-Type', 'text/xml');
    }

    // Crear o recuperar contexto de la llamada
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
        'speechTimeout' => 'auto',
        'bargeIn' => true
    ]);
    $gather->say(
        'Hola, bienvenido a Estética Contemporánea, ¿en qué puedo ayudarte?',
        ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
    );
    $response->redirect(url('/voice/incoming'));
    return response($response)->header('Content-Type', 'text/xml');
});

// Procesar selección y pedir fecha/hora
Route::match(['get', 'post'], '/voice/gather', function () {
    $callSid = request('CallSid');
    $digit = request('Digits');
    $speechResult = strtolower(trim(request('SpeechResult') ?? ''));
        $confidence = request('Confidence') ?? 0; // Default to 0 if not provided
    
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
    // Persist short memory of last input
    $mem = $context->data ?? [];
    $mem[] = [
        'step' => 'gather',
        'input' => $speechResult ?: $digit,
        'intent' => null,
        'confidence' => $confidence,
        'ts' => now()->toIso8601String(),
    ];
    if (count($mem) > 6) { array_shift($mem); }
    $context->data = $mem;
    $context->save();
    $response = new VoiceResponse();
    $ai = new AIAssistant();
    
    // Detectar intención con IA
    $intent = $ai->detectIntent($speechResult);
    
    Log::info('🤖 [AI INTENT DETECTION]', [
        'call_sid' => $callSid,
        'input' => $speechResult,
        'detected_intent' => $intent
    ]);

    // Update memory with detected intent
    $mem = $context->data ?? [];
    if (!empty($mem)) {
        $mem[count($mem)-1]['intent'] = $intent;
        $context->data = $mem;
        $context->save();
    }

    // Confidence-based brief confirmation (avoid if going to AI info branch)
        if ($intent !== 'info' && !empty($speechResult) && is_numeric($confidence) && (float)$confidence >= 0.6) {
        $response->say('Te he entendido: ' . $speechResult . '.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    }
    
    // Si el usuario hace una pregunta, responder con IA
    if ($intent === 'info' && !empty($speechResult)) {
        Log::info('🧠 [AI REQUEST]', ['call_sid' => $callSid, 'question' => $speechResult]);
        // Build short notes from memory for AI context
        $notes = collect($context->data ?? [])->map(function($e){ return ($e['step'] ?? '') . ':' . ($e['input'] ?? ''); })->implode(' | ');
        $aiResponse = $ai->respondToQuestion($speechResult, [
            'family' => $context->family ?? null,
            'product' => optional($context->product)->name,
            'requested_date' => $context->requested_date ?? null,
            'requested_time' => $context->requested_time ?? null,
            'step' => $context->step ?? null,
            'notes' => $notes,
        ]);
        
        Log::info('💬 [AI RESPONSE]', [
            'call_sid' => $callSid,
            'question' => $speechResult,
            'answer' => $aiResponse,
            'answer_length' => strlen($aiResponse ?? '')
        ]);
        
        if ($aiResponse) {
            $response->say(
                '<speak>' . $aiResponse . ' <break time="500ms"/></speak>',
                ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
            );
            $gather = $response->gather([
                'input' => 'speech dtmf',
                'language' => 'es-ES',
                'speechModel' => 'phone_call',
                'hints' => 'facial, manos, sí, si, ok, vale, agendar, cita, precio, información, 1, 2',
                'action' => url('/voice/gather'),
                'timeout' => 10,
                'speechTimeout' => 'auto',
                'bargeIn' => true
            ]);
            $gather->say(
                '<speak>¿Te gustaría agendar un tratamiento? Dime facial o manos.</speak>',
                ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
            );
            return response($response)->header('Content-Type', 'text/xml');
        }
    }
    
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
        // Intentar respuesta con IA si no entendió
        if (!empty($speechResult)) {
            Log::info('🧠 [AI FALLBACK REQUEST]', ['call_sid' => $callSid, 'input' => $speechResult]);
            $aiResponse = $ai->respondToQuestion($speechResult);
            Log::info('💬 [AI FALLBACK RESPONSE]', [
                'call_sid' => $callSid,
                'answer' => $aiResponse,
                'answer_length' => strlen($aiResponse ?? '')
            ]);
            if ($aiResponse) {
                $response->say(
                    '<speak>' . $aiResponse . '</speak>',
                    ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
                );
                $response->redirect(url('/voice/incoming'));
                return response($response)->header('Content-Type', 'text/xml');
            }
        }
        
        $response->say(
            '<speak>Disculpa, no entendí bien. <break time="300ms"/> ¿Prefieres tratamientos faciales o para manos?</speak>',
            ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
        );
        $response->redirect(url('/voice/incoming'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    // Guardar familia en contexto
    $context->update(['family' => $family, 'step' => 'selecting_product']);

    $products = Product::where('family', $family)->where('active', true)->get();
    if ($products->isEmpty()) {
        $response->say(
            '<speak>Lo siento, <break time="300ms"/> no tenemos servicios disponibles en esta categoría en este momento.</speak>',
            ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
        );
        $response->hangup();
        return response($response)->header('Content-Type', 'text/xml');
    }

    $gather = $response->gather([
        'input' => 'speech dtmf',
        'language' => 'es-ES',
        'speechModel' => 'phone_call',
        'hints' => implode(',', $products->pluck('name')->toArray()) . ', volver, primero, segundo, tercero, uno, dos, tres',
        'action' => url('/voice/product'),
        'timeout' => 10,
        'speechTimeout' => 'auto',
        'bargeIn' => true
    ]);
    $gather->say(
        '<speak>Perfecto, estos son nuestros servicios disponibles:</speak>',
        ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
    );
    foreach ($products as $index => $p) {
        $priceEuros = number_format($p->price_cents / 100, 2);
        $gather->say(
            '<speak><prosody rate="0.98">' . ($index + 1) . '. ' . $p->name . '</prosody> — duración ' . $p->duration_minutes . ' minutos, precio ' . $priceEuros . ' euros. <break time="300ms"/></speak>', 
            ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
        );
    }
    $gather->say(
        '<speak>¿Cuál te gustaría agendar? Puedes decir el nombre o el número.</speak>',
        ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
    );
    $response->redirect(url('/voice/product'));
    return response($response)->header('Content-Type', 'text/xml');
});

// Elegir producto y pedir fecha/hora
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
        $product = $products->first(function ($p) use ($speechResult) {
            return strpos($speechResult, strtolower($p->name)) !== false;
        });
    }
    
    $productIndex = $products->search($product);
    
    if ($productIndex < 0 || $productIndex >= $products->count()) {
        $response->say('Selección inválida. Presione 0 para volver al menú.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/gather'));
        return response($response)->header('Content-Type', 'text/xml');
    }
    
    $product = $products->get($productIndex);
    
    // Guardar producto en contexto
    $context->update(['product_id' => $product->id, 'step' => 'requesting_datetime']);

    $response->say('<speak>Has elegido ' . $product->name . '. <break time="300ms"/> Indica la fecha deseada. Puedes decirla, por ejemplo <prosody rate="0.98">20 de diciembre</prosody>, o marcar los dígitos. <break time="500ms"/></speak>', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    
    $gather = $response->gather([
        'input' => 'speech dtmf',
        'language' => 'es-ES',
        'speechModel' => 'phone_call',
        'hints' => 'próximo disponible, hoy, mañana, lunes, martes, miércoles, jueves, viernes, enero, febrero, marzo, abril, mayo, junio, julio, agosto, septiembre, octubre, noviembre, diciembre',
        'action' => url('/voice/date'),
        'timeout' => 10,
        'speechTimeout' => 'auto',
        'bargeIn' => true
    ]);
    $gather->say('Diga la fecha o presione 9 para el próximo día disponible.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
    $response->redirect(url('/voice/product'));
    return response($response)->header('Content-Type', 'text/xml');
});

// Procesar fecha y pedir hora
Route::match(['get', 'post'], '/voice/date', function () {
    $callSid = request('CallSid');
    $digits = request('Digits');
    $speechResult = strtolower(trim(request('SpeechResult') ?? ''));
    
    $context = CallContext::where('call_sid', $callSid)->first();
    $response = new VoiceResponse();
    
    // Si presiona 9, usar próximo día disponible
    if ($digits === '9') {
        $availabilityService = new AvailabilityService();
        $product = $context->product;
        $nextSlot = $availabilityService->findNextAvailableSlot(Carbon::now(), $product->duration_minutes);
        
        if (!$nextSlot) {
            $response->say('Lo sentimos, no hay disponibilidad en los próximos 30 días.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->say('Por favor contacte con nosotros directamente.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->hangup();
            return response($response)->header('Content-Type', 'text/xml');
        }
        
        // Crear cita directamente
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
    
    // Parsear fecha desde voz o DTMF
    $requestedDate = null;

    // 1) Voz: palabras clave comunes
    if (!empty($speechResult)) {
        if (strpos($speechResult, 'hoy') !== false) {
            $requestedDate = Carbon::today();
        } elseif (strpos($speechResult, 'mañana') !== false) {
            $requestedDate = Carbon::tomorrow();
        } else {
            // Día de la semana (próximo lunes, martes, ...)
            $dowMap = [
                'lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'miercoles' => 3,
                'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'sabado' => 6, 'domingo' => 0
            ];
            foreach ($dowMap as $name => $dow) {
                if (strpos($speechResult, $name) !== false) {
                    $base = Carbon::today();
                    $delta = ($dow - $base->dayOfWeek + 7) % 7;
                    // Si dijo "próximo" y es hoy, ir a la siguiente semana
                    if ($delta === 0 || strpos($speechResult, 'próximo') !== false || strpos($speechResult, 'proximo') !== false) {
                        $delta = ($delta === 0) ? 7 : $delta;
                    }
                    $requestedDate = $base->copy()->addDays($delta);
                    break;
                }
            }

            // "20 de diciembre" o "diciembre 20"
            if ($requestedDate === null) {
                $monthMap = [
                    'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
                    'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12
                ];
                $day = null; $month = null;
                if (preg_match('/(\d{1,2}).*?(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)/', $speechResult, $m)) {
                    $day = (int)$m[1];
                    $month = $monthMap[$m[2]] ?? null;
                } elseif (preg_match('/(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre).*?(\d{1,2})/', $speechResult, $m)) {
                    $month = $monthMap[$m[1]] ?? null;
                    $day = (int)$m[2];
                }
                if ($day !== null && $month !== null) {
                    $requestedDate = Carbon::create(Carbon::now()->year, $month, $day);
                }

                // "20/12" o "20-12"
                if ($requestedDate === null && preg_match('/(\d{1,2})[\/-](\d{1,2})/', $speechResult, $m)) {
                    $requestedDate = Carbon::create(Carbon::now()->year, (int)$m[2], (int)$m[1]);
                }
            }
        }
    }

    // 2) DTMF: formato DDMM
    if ($requestedDate === null && !empty($digits) && strlen($digits) === 4) {
        $day = (int)substr($digits, 0, 2);
        $month = (int)substr($digits, 2, 2);
        $requestedDate = Carbon::create(Carbon::now()->year, $month, $day);
    }

    if ($requestedDate === null) {
        $response->say('No entendí la fecha. Diga, por ejemplo, 20 de diciembre o 20/12, o presione 9 para el próximo disponible.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/product'));
        return response($response)->header('Content-Type', 'text/xml');
    }

    try {
        // Si la fecha es pasada, asumir año siguiente
        if ($requestedDate->isPast()) {
            $requestedDate->addYear();
        }
        
        // Validar día laboral
        if ($requestedDate->isWeekend()) {
            $response->say('La fecha seleccionada cae en fin de semana. No trabajamos sábados ni domingos.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->say('Presione 9 para el próximo día disponible o ingrese otra fecha.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
            $response->redirect(url('/voice/product'));
            return response($response)->header('Content-Type', 'text/xml');
        }
        
        $context->update(['requested_date' => $requestedDate->toDateString()]);
        
        $response->say(
            'Fecha: ' . $requestedDate->locale('es')->isoFormat('D [de] MMMM [de] YYYY') . '.', 
            ['language' => 'es-ES', 'voice' => 'Polly.Lucia']
        );
        $response->say('Ahora ingrese la hora deseada en formato de 24 horas.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->say('Por ejemplo, para las 15:30, marque: 1, 5, 3, 0.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        
        $gather = $response->gather([
            'input' => 'speech dtmf',
            'language' => 'es-ES',
            'speechModel' => 'phone_call',
            'action' => url('/voice/confirm'),
            'timeout' => 10,
            'speechTimeout' => 'auto',
            'bargeIn' => true
        ]);
        $gather->say('Diga la hora, por ejemplo quince treinta, o marque cuatro dígitos como 1 5 3 0.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/date'));
        
    } catch (\Exception $e) {
        $response->say('Fecha inválida. Por favor intente nuevamente.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/product'));
    }
    
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
    
    // Parsear hora desde DTMF o voz
    if (!empty($digits) && strlen($digits) === 4) {
        $hour = (int)substr($digits, 0, 2);
        $minute = (int)substr($digits, 2, 2);
    } elseif (!empty($speechResult)) {
        // Normalizar expresiones frecuentes
        $s = $speechResult;
        $s = str_replace([' y media', ' y treinta'], ' 30', $s);
        $s = str_replace([' y cuarto', ' y quince'], ' 15', $s);
        $s = str_replace([' en punto'], '', $s);

        // Palabras a números básicos (doce, once, diez, nueve, ocho, siete, seis, cinco, cuatro, tres, dos, uno)
        $wordsMap = [
            'doce' => 12, 'once' => 11, 'diez' => 10, 'nueve' => 9, 'ocho' => 8, 'siete' => 7,
            'seis' => 6, 'cinco' => 5, 'cuatro' => 4, 'tres' => 3, 'dos' => 2, 'uno' => 1,
        ];
        foreach ($wordsMap as $w => $n) {
            $s = preg_replace('/\b' . $w . '\b/', (string)$n, $s);
        }

        // Capturar primera hora y posibles minutos
        preg_match_all('/\d{1,2}/', $s, $nums);
        if (!empty($nums[0])) {
            $hour = (int)$nums[0][0];
            if (isset($nums[0][1])) {
                $minute = (int)$nums[0][1];
            } else {
                if (strpos($s, '30') !== false) {
                    $minute = 30;
                } elseif (strpos($s, '15') !== false) {
                    $minute = 15;
                } else {
                    $minute = 0;
                }
            }

            // Ajustar por AM/PM o palabras
            if (strpos($s, 'tarde') !== false || strpos($s, 'pm') !== false) {
                if ($hour < 12) { $hour += 12; }
            } elseif (strpos($s, 'mañana') !== false || strpos($s, 'am') !== false) {
                if ($hour == 12) { $hour = 0; }
            }
        }
    }
    
    if ($hour === null || $minute === null) {
        $response->say('<speak>No entendí la hora. <break time="200ms"/> Dime, por ejemplo, quince treinta, o marca 1 5 3 0.</speak>', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
        $response->redirect(url('/voice/date'));
        return response($response)->header('Content-Type', 'text/xml');
    }

    // Confirmación breve de hora entendida
    $response->say('Hora entendida: ' . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($minute, 2, '0', STR_PAD_LEFT) . '.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);

    
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
                $response->say('No hay disponibilidad para ese día. Presione 9 para el próximo disponible.', ['language' => 'es-ES', 'voice' => 'Polly.Lucia']);
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
        
        // Limpiar contexto
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
