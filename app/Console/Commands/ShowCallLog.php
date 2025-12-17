<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ShowCallLog extends Command
{
    protected $signature = 'call:log {call_sid?}';
    protected $description = 'Mostrar logs de una llamada específica o la última llamada';

    public function handle()
    {
        $callSid = $this->argument('call_sid');
        $logFile = storage_path('logs/laravel.log');
        
        if (!file_exists($logFile)) {
            $this->error('No se encontró el archivo de logs');
            return 1;
        }

        $this->info("📞 Buscando logs de llamadas...\n");

        $logs = file_get_contents($logFile);
        $lines = explode("\n", $logs);
        
        $callLogs = [];
        $currentEntry = '';
        
        foreach ($lines as $line) {
            // Si la línea empieza con fecha, es una nueva entrada
            if (preg_match('/^\[\d{4}-\d{2}-\d{2}/', $line)) {
                if ($currentEntry) {
                    // Procesar entrada anterior
                    if ($this->matchesFilter($currentEntry, $callSid)) {
                        $callLogs[] = $currentEntry;
                    }
                }
                $currentEntry = $line;
            } else {
                // Continuar con la entrada actual
                $currentEntry .= "\n" . $line;
            }
        }
        
        // Procesar última entrada
        if ($currentEntry && $this->matchesFilter($currentEntry, $callSid)) {
            $callLogs[] = $currentEntry;
        }

        if (empty($callLogs)) {
            $this->warn('No se encontraron logs para esta llamada');
            return 0;
        }

        // Mostrar solo los últimos N logs
        $recentLogs = array_slice($callLogs, -50);
        
        $this->info("Mostrando últimos " . count($recentLogs) . " eventos:\n");
        $this->line(str_repeat('=', 80));
        
        foreach ($recentLogs as $log) {
            $this->formatLogEntry($log);
        }
        
        $this->line(str_repeat('=', 80));
        $this->info("\nTotal de eventos: " . count($callLogs));
        
        return 0;
    }

    private function matchesFilter($entry, $callSid)
    {
        // Filtrar por emojis de eventos importantes
        $hasMarker = preg_match('/📞|🎙️|✅|❌|⚠️|🛍️|📅|⏰|🤖|🧠|💬|🔵|🔍/', $entry);
        
        if (!$hasMarker) {
            return false;
        }
        
        if ($callSid) {
            return strpos($entry, $callSid) !== false;
        }
        
        return true;
    }

    private function formatLogEntry($entry)
    {
        // Extraer timestamp
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $entry, $matches)) {
            $timestamp = $matches[1];
            $this->comment($timestamp);
        }
        
        // Colorear según el emoji/tipo
        if (strpos($entry, '📞 [INCOMING CALL]') !== false) {
            $this->line('<fg=cyan>📞 LLAMADA ENTRANTE</>');
        } elseif (strpos($entry, '🎙️ [GATHER INPUT]') !== false) {
            $this->line('<fg=blue>🎙️ INPUT RECIBIDO</>');
        } elseif (strpos($entry, '✅ [FAMILY SELECTED]') !== false) {
            $this->line('<fg=green>✅ FAMILIA SELECCIONADA</>');
        } elseif (strpos($entry, '✅ [PRODUCT CONFIRMED]') !== false) {
            $this->line('<fg=green>✅ PRODUCTO CONFIRMADO</>');
        } elseif (strpos($entry, '✅ [APPOINTMENT CREATED]') !== false) {
            $this->line('<fg=green;options=bold>✅ CITA CREADA</>');
        } elseif (strpos($entry, '🤖 [AI INTENT DETECTION]') !== false) {
            $this->line('<fg=magenta>🤖 DETECCIÓN DE INTENCIÓN (AI)</>');
        } elseif (strpos($entry, '🧠 [AI REQUEST]') !== false || strpos($entry, '🔵 [OPENAI API REQUEST]') !== false) {
            $this->line('<fg=magenta>🧠 SOLICITUD A OPENAI</>');
        } elseif (strpos($entry, '💬 [AI RESPONSE]') !== false || strpos($entry, '✅ [OPENAI API SUCCESS]') !== false) {
            $this->line('<fg=green>💬 RESPUESTA DE OPENAI</>');
        } elseif (strpos($entry, '❌') !== false) {
            $this->line('<fg=red>❌ ERROR</>');
        } elseif (strpos($entry, '⚠️') !== false) {
            $this->line('<fg=yellow>⚠️ ADVERTENCIA</>');
        }
        
        // Extraer datos JSON si existen
        if (preg_match('/{.*}$/s', $entry, $matches)) {
            $jsonData = @json_decode($matches[0], true);
            if ($jsonData) {
                foreach ($jsonData as $key => $value) {
                    if (is_string($value) || is_numeric($value)) {
                        $this->line("  <fg=gray>$key:</>  " . $value);
                    } elseif (is_array($value)) {
                        $this->line("  <fg=gray>$key:</>  " . json_encode($value, JSON_UNESCAPED_UNICODE));
                    }
                }
            }
        }
        
        $this->newLine();
    }
}
