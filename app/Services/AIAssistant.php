<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIAssistant
{
    private $apiKey;
    private $model = 'gpt-4o-mini'; // Modelo más económico y rápido
    private $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY');
    }

    /**
     * Generar respuesta conversacional sobre servicios
     */
    public function respondToQuestion(string $userInput, array $context = []): ?string
    {
        $startTime = microtime(true);
        Log::info('🔵 [OPENAI API REQUEST]', [
            'user_input' => $userInput,
            'model' => $this->model,
            'timestamp' => now()->toIso8601String()
        ]);
        
        try {
            // Construir contexto conversacional breve
            $ctxSummaryParts = [];
            if (!empty($context['family'])) { $ctxSummaryParts[] = 'familia: ' . $context['family']; }
            if (!empty($context['product'])) { $ctxSummaryParts[] = 'producto: ' . $context['product']; }
            if (!empty($context['requested_date'])) { $ctxSummaryParts[] = 'fecha solicitada: ' . $context['requested_date']; }
            if (!empty($context['requested_time'])) { $ctxSummaryParts[] = 'hora solicitada: ' . $context['requested_time']; }
            if (!empty($context['step'])) { $ctxSummaryParts[] = 'paso actual: ' . $context['step']; }
            $ctxSummary = implode(' | ', $ctxSummaryParts);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(5)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'max_tokens' => 120,
                'temperature' => 0.6,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => (
                            'Eres un asistente amable y natural de Contemporánea Estética. '
                            . 'Habla en español latino, cálido y cercano, en 1–2 frases. '
                            . 'Usa escucha activa (parafrasea brevemente lo entendido) y ofrece el siguiente paso con tacto. '
                            . 'Si hay ambigüedad, pide una aclaración corta. '
                            . 'Servicios: faciales (limpieza, rejuvenecimiento, peeling) y manos (manicura, tratamientos). '
                            . 'Mantén respuestas breves y sin enumeraciones largas. '
                            . 'Contexto: ' . ($ctxSummary ?: 'sin contexto')
                        )
                    ],
                    // Ejemplos breves para estilo y control
                    [ 'role' => 'user', 'content' => 'hola' ],
                    [ 'role' => 'assistant', 'content' => '¡Hola! Te escucho, ¿qué te gustaría hacer hoy?' ],
                    [ 'role' => 'user', 'content' => 'qué faciales tienen' ],
                    [ 'role' => 'assistant', 'content' => 'Ofrecemos limpiezas, rejuvenecimiento y peeling. Si quieres, te reservo uno.' ],
                    [
                        'role' => 'user',
                        'content' => $userInput
                    ]
                ]
            ]);
            
            $duration = round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $aiAnswer = $response->json('choices.0.message.content');
                $usage = $response->json('usage');
                
                Log::info('✅ [OPENAI API SUCCESS]', [
                    'user_input' => $userInput,
                    'ai_response' => $aiAnswer,
                    'duration_ms' => $duration,
                    'tokens_prompt' => $usage['prompt_tokens'] ?? null,
                    'tokens_completion' => $usage['completion_tokens'] ?? null,
                    'tokens_total' => $usage['total_tokens'] ?? null
                ]);
                
                return $aiAnswer;
            } else {
                Log::warning('❌ [OPENAI API ERROR]', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'duration_ms' => $duration
                ]);
                return null;
            }
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            Log::error('❌ [OPENAI API EXCEPTION]', [
                'message' => $e->getMessage(),
                'duration_ms' => $duration,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Detectar intención del usuario
     */
    public function detectIntent(string $userInput): string
    {
        // Normalizar entrada para comparaciones más robustas
        $input = strtolower(trim($userInput));
        $input = preg_replace('/[\.,;:!\?]/', '', $input);

        // Saludos y small talk -> tratamos como info para responder cálido en vez de "unknown"
        if (preg_match('/^(hola|buenas|buenos dias|buenas tardes|buenas noches|que tal|qué tal|hola que tal|hey)/', $input)) {
            return 'info';
        }

        // Palabras clave para agendar
        if (preg_match('/(agendar|reservar|cita|appointment|booking|quiero|booking|me gustaría)/', $input)) {
            return 'schedule';
        }

        // Palabras clave para cambiar familia
        if (preg_match('/(facial|manos|otro|diferente|cambiar)/', $input)) {
            return 'change_family';
        }

        // Palabras clave para información
        if (preg_match('/(qué|cuál|información|servicios|precio|duración|cómo|más|detalles)/', $input)) {
            return 'info';
        }

        // Palabras clave para confirmar
        if (preg_match('/(sí|si|claro|ok|perfecto|bien|vale)/', $input)) {
            return 'confirm';
        }

        // Palabras clave para negar
        if (preg_match('/(no|nope|no gracias|no quiero|cancel)/', $input)) {
            return 'deny';
        }

        // Si hay texto pero no coincidió con reglas, tratamos como info para mantener respuesta amable
        if (!empty($input)) {
            return 'info';
        }

        return 'unknown';
    }
}
