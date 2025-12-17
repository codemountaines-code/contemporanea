git config --global user.name "Codemountain"
git config --global user.email "codemountain.es@gmail.com"

cd ~/projects/contemporanea
git init
git add .
git commit -m "Initial commit"

git remote add origin https://github.com/codemountaines-code/contemporanea.git
git branch -M main
git push -u origin main

ls -la ~/.ssh/
ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa -N ""
cat ~/.ssh/id_rsa.pub


ssh -T git@github.com

Siguientes mejoras que puedo implementar ya:

Persistir contexto por CallSid (familia, producto, fecha/hora solicitada).
Parsear fecha/hora desde DTMF y validar en calendario.
Comprobar solapamientos antes de crear la cita y confirmar la hora exacta.
Añadir opción de repetir menú o pasar a humano si hay error.

# Ejecuta la nueva migración
./vendor/bin/sail artisan migrate

# Limpia cache de rutas
./vendor/bin/sail artisan route:clear

# Verifica rutas
./vendor/bin/sail artisan route:list | grep voice

# Arranca servidor
./vendor/bin/sail artisan serve

# En otra terminal, expón con ngrok
ngrok http 8000


nfiguración Twilio:

URL: https://<tu-ngrok>/voice/incoming (POST)



Flujo de llamada:

Cliente llama → Saludo y menú (1=facial, 2=manos, 0=repetir)
Lista productos con precios y duración
Pide fecha (DDMM) o 9 para próximo disponible
Valida día laboral
Pide hora (HHMM)
Valida disponibilidad y horario
Confirma cita o sugiere alternativas
Agenda y confirma por voz


PASOS PARA PROBAR EN ENTORNO DE DESARROLLO 

cd ~/projects/contemporanea
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed --class=ProductSeeder
./vendor/bin/sail artisan route:list | grep voice


Levantar la app:
Opción Sail (puerto 80): ./vendor/bin/sail up -d
Opción serve (puerto 8000): ./vendor/bin/sail artisan serve

Exponer con ngrok:
Si usas serve: ngrok http 8000
Si usas Sail en puerto 80: ngrok http 80

Configurar Twilio (en tu número):

Voice → “A Call Comes In”: método POST a https://<tu-subdominio-ngrok>/voice/incoming


Llamada real:
Llama a tu número de Twilio y sigue el menú:
1=faciales, 2=manos, 0=repetir

Elige servicio
Ingresa fecha (DDMM) o 9 para próximo disponible
Ingresa hora (HHMM)
Se valida disponibilidad y se agenda
Pruebas rápidas sin llamada (opcional):

Simula webhook:
curl -X POST https://unprotested-billy-unmelodiously.ngrok-free.dev/voice/incoming


LOGS I CONFIGURACION 
./vendor/bin/sail logs -f laravel.test   # si usas sail up

cd ~/projects/contemporanea
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan cache:clear

./vendor/bin/sail down
./vendor/bin/sail up -d



curl -X POST  https://unprotested-billy-unmelodiously.ngrok-free.dev/voice/incoming \-d "CallSid=CA123456789abcdef123456789abcdef&From=%2B34123456789"


curl -X POST https://unprotested-billy-unmelodiously.ngrok-free.dev/voice/incoming \
  -d "CallSid=CA123456789abcdef123456789abcdef&From=%2B34123456789"


curl.exe -X POST "https://unprotested-billy-unmelodiously.ngrok-free.dev/voice/incoming" `
  -d "CallSid=CA123456789abcdef123456789abcdef&From=%2B34123456789"


  curl.exe -X POST "https://unprotested-billy-unmelodiously.ngrok-free.dev/voice/incoming" -d "CallSid=CA123456789abcdef123456789abcdef&From=%2B34123456789"


  CON AI 

  Mi recomendación para ti:

Comienza con OpenAI GPT-4 (fácil de integrar, resultados excelentes)
Usa el cliente PHP: composer require openai-php/client
Aplícalo solo cuando el usuario haga preguntas no previstas ("¿Qué es un facial?" → IA responde)
Mantén los flujos básicos (seleccionar, agendar) sin IA para rapidez
¿Implemento OpenAI GPT-4 con fallback a conversación robótica si falla?

codemountai.es --> creada  key en openai pagada 5 dolares para pruebas 
17-12-25



TRAZABILIDAD LLAMADA , 

./vendor/bin/sail artisan call:log
tail -f storage/logs/laravel.log | grep -E '📞|🎙️|✅|🧠|💬'


Cómo Ver los Logs:
Opción 1: Ver todos los logs recientes
./vendor/bin/sail artisan call:log

Opción 2: Ver logs de una llamada específica
./vendor/bin/sail artisan call:log CA1234567890abcdef

Opción 3: Ver logs en tiempo real
./vendor/bin/sail logs -f

Opción 4: Filtrar manualmente
tail -f storage/logs/laravel.log | grep -E '📞|🎙️|✅|🧠|💬'

El comando call:log muestra un resumen visual y organizado de todas las interacciones, incluyendo tiempos de respuesta de OpenAI, tokens consumidos, y todos los parámetros de cada paso del flujo.


