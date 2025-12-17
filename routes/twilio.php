<?php

use Illuminate\Support\Facades\Route;
use Twilio\Rest\Client;
use App\Models\Product;
use App\Models\Appointment;
use Illuminate\Support\Carbon;

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
Route::post('/voice/incoming', function () {
    $response = new \Twilio\Twiml();
    $response->say('Bienvenido a Contemporánea Estética.');
    $gather = $response->gather(['input' => 'dtmf', 'numDigits' => 1, 'action' => url('/voice/gather')]);
    $gather->say('Indique la familia del servicio: Presione 1 para faciales, 2 para manos.');
    $response->say('No se detectó elección.');
    $response->redirect(url('/voice/incoming'));
    return response($response)->header('Content-Type', 'text/xml');
});

// Procesar selección y pedir fecha/hora
Route::post('/voice/gather', function () {
    $digit = request('Digits');
    $family = $digit === '1' ? 'facial' : ($digit === '2' ? 'manos' : null);

    $response = new \Twilio\Twiml();
    if (!$family) {
        $response->say('Selección inválida.');
        $response->redirect(url('/voice/incoming'));
        return response($response)->header('Content-Type', 'text/xml');
    }

    $products = Product::where('family', $family)->where('active', true)->limit(3)->get();
    if ($products->isEmpty()) {
        $response->say('No hay servicios disponibles en esta familia.');
        return response($response)->header('Content-Type', 'text/xml');
    }

    $response->say('Servicios disponibles.');
    foreach ($products as $index => $p) {
        $response->say(($index + 1) . '. ' . $p->name . ' duración ' . $p->duration_minutes . ' minutos.');
    }
    $gather = $response->gather(['input' => 'dtmf', 'numDigits' => 1, 'action' => url('/voice/product')]);
    $gather->say('Elija el servicio presionando un número.');
    return response($response)->header('Content-Type', 'text/xml');
});

// Elegir producto y pedir fecha/hora
Route::post('/voice/product', function () {
    $digit = request('Digits');
    $family = 'facial'; // simplificado: en producción, almacenar en sesión/DB
    $products = Product::where('family', $family)->where('active', true)->limit(3)->get();
    $product = $products->get(((int)$digit) - 1);

    $response = new \Twilio\Twiml();
    if (!$product) {
        $response->say('Selección inválida.');
        $response->redirect(url('/voice/incoming'));
        return response($response)->header('Content-Type', 'text/xml');
    }

    $response->say('Ha elegido ' . $product->name . '.');
    $response->say('Por favor, ingrese fecha y hora en formato 24 horas, por ejemplo, 1 7 Diciembre a las 1 7 3 0.');
    $gather = $response->gather(['input' => 'speech dtmf', 'timeout' => 5, 'action' => url('/voice/confirm')]);
    $gather->say('Diga la fecha y hora, o márquela en el teclado.');
    return response($response)->header('Content-Type', 'text/xml');
});

// Confirmar y registrar cita (simplificado)
Route::post('/voice/confirm', function () {
    $response = new \Twilio\Twiml();

    // Simplificación: asignar hora fija + ahora + duración
    $product = Product::where('active', true)->first();
    if (!$product) {
        $response->say('No hay servicios disponibles.');
        return response($response)->header('Content-Type', 'text/xml');
    }
    $start = Carbon::now()->addHour();
    $end = (clone $start)->addMinutes($product->duration_minutes);

    Appointment::create([
        'customer_name' => 'Cliente',
        'customer_phone' => request('Caller') ?? 'unknown',
        'product_id' => $product->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'status' => 'scheduled',
    ]);

    $response->say('Su cita ha sido agendada para ' . $start->format('d-m-Y H:i') . '. Gracias.');
    $response->hangup();
    return response($response)->header('Content-Type', 'text/xml');
});
