<?php
namespace App\Http\Controllers;

    use App\Models\Message;
    use App\Models\ChatSession;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Http;
    use App\Services\LumorahAIService;
    use Pusher\Pusher;
    use App\Models\Usuario;

    class ChatController extends Controller
    {
        protected $lumorahService;
        protected $supportedLanguages = ['es', 'en', 'fr', 'pt'];
        protected $pusher;

        public function __construct()
        {
            $this->middleware('auth:sanctum');
            $this->pusher = new Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                [
                    'cluster' => env('PUSHER_APP_CLUSTER'),
                    'useTLS' => true,
                ]
            );
        }

        private function initializeService(Request $request)
        {
            $language = $request->input('language', 'es');
            if (!in_array($language, $this->supportedLanguages)) {
                $language = 'es';
            }

            $user = Auth::user();
            $userName = null;
            if ($user) {
                $usuario = Usuario::where('id', $user->id)->first();
                $userName = $usuario ? $usuario->nombre : null;
            }

            if (!$userName && $request->session_id) {
                $session = ChatSession::where('id', $request->session_id)
                    ->where('user_id', Auth::id())
                    ->first();
                $userName = $session->user_name ?? null;
            }

            Log::info('Initializing service with user name:', ['name' => $userName]);

            $this->lumorahService = new LumorahAIService($userName, $language);
        }

        protected function generateSessionTitle($message)
        {
            return substr($message, 0, 50) . (strlen($message) > 50 ? '...' : '');
        }

        public function getSessions(Request $request)
        {
            try {
                $query = ChatSession::where('user_id', Auth::id())
                    ->orderBy('created_at', 'desc');

                if ($request->query('saved', true)) {
                    $query->where('is_saved', true);
                }

                $sessions = $query->get()->map(function ($session) {
                    return [
                        'id' => $session->id,
                        'user_id' => $session->user_id,
                        'title' => $session->title,
                        'is_saved' => (bool)$session->is_saved,
                        'created_at' => $session->created_at->toDateTimeString(),
                        'updated_at' => $session->updated_at->toDateTimeString(),
                        'deleted_at' => $session->deleted_at?->toDateTimeString(),
                    ];
                });

                return response()->json([
                    'success' => true,
                    'data' => $sessions,
                    'count' => $sessions->count()
                ], 200);
            } catch (\Exception $e) {
                Log::error('Error al obtener sesiones: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Error al obtener sesiones',
                    'message' => $e->getMessage()
                ], 500);
            }
        }

        public function processAudio(Request $request)
        {
            Log::info('Solicitud recibida en processAudio', [
                'user_id' => Auth::id(),
                'audio_file' => $request->hasFile('audio') ? $request->file('audio')->getClientOriginalName() : 'No file',
            ]);

            if ($request->hasFile('audio')) {
                $audio = $request->file('audio');
                Log::info('Archivo recibido: ' . $audio->getClientOriginalName());
                Log::info('Tipo MIME detectado por el servidor: ' . $audio->getMimeType());

                $request->validate([
                    'audio' => 'required|file|mimetypes:audio/m4a,audio/mp4,audio/aac,audio/wav,audio/mpeg,video/mp4|max:25600',
                ]);

                try {
                    $audioPath = $audio->getPathname();
                    Log::info('Procesando audio: ' . $audioPath);

                    return response()->json([
                        'success' => true,
                        'message' => 'Audio recibido correctamente',
                    ], 200);
                } catch (\Exception $e) {
                    Log::error('Excepción al procesar audio: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'error' => 'Error al procesar el audio',
                        'message' => $e->getMessage(),
                    ], 500);
                }
            }

            return response()->json([
                'success' => false,
                'error' => 'No se proporcionó un archivo de audio',
            ], 400);
        }

        public function deleteSession($sessionId)
        {
            DB::beginTransaction();
            try {
                $session = ChatSession::where('id', $sessionId)
                    ->where('user_id', Auth::id())
                    ->firstOrFail();

                Message::where('chat_session_id', $sessionId)->delete();
                $session->delete();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Conversación eliminada exitosamente'
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error al eliminar sesión: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Error al eliminar la conversación',
                    'message' => $e->getMessage()
                ], 500);
            }
        }

        public function saveChatSession(Request $request)
        {
            $request->validate([
                'title' => 'required|string|max:100',
                'messages' => 'required|array',
                'messages.*.text' => 'required|string',
                'messages.*.is_user' => 'required|boolean',
                'messages.*.created_at' => 'required|date',
                'session_id' => 'nullable|exists:chat_sessions,id',
            ]);

            DB::beginTransaction();
            try {
                $userId = Auth::id();
                $sessionId = $request->session_id;

                if ($sessionId) {
                    $session = ChatSession::where('id', $sessionId)
                        ->where('user_id', $userId)
                        ->firstOrFail();
                    $session->update([
                        'title' => $request->title,
                        'is_saved' => true,
                    ]);
                } else {
                    $session = ChatSession::create([
                        'user_id' => $userId,
                        'title' => $request->title,
                        'is_saved' => true,
                    ]);
                }

                if ($sessionId) {
                    Message::where('chat_session_id', $sessionId)->delete();
                }

                foreach ($request->messages as $msg) {
                    Message::create([
                        'chat_session_id' => $session->id,
                        'user_id' => $msg['is_user'] ? $userId : null,
                        'text' => $msg['text'],
                        'is_user' => $msg['is_user'],
                        'created_at' => $msg['created_at'],
                        'updated_at' => $msg['created_at'],
                    ]);
                }

                DB::commit();
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $session->id,
                        'user_id' => $session->user_id,
                        'title' => $session->title,
                        'is_saved' => $session->is_saved,
                        'created_at' => $session->created_at->toDateTimeString(),
                        'updated_at' => $session->updated_at->toDateTimeString(),
                    ],
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error al guardar sesión: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Error al guardar el chat',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        public function getSessionMessages($sessionId)
        {
            try {
                $session = ChatSession::where('id', $sessionId)
                    ->where('user_id', Auth::id())
                    ->firstOrFail();

                $messages = $session->messages()
                    ->orderBy('created_at')
                    ->get()
                    ->map(function ($msg) {
                        return [
                            'id' => $msg->id,
                            'chat_session_id' => $msg->chat_session_id,
                            'user_id' => $msg->user_id,
                            'text' => $msg->text,
                            'is_user' => $msg->is_user,
                            'created_at' => $msg->created_at->toDateTimeString(),
                            'updated_at' => $msg->updated_at->toDateTimeString(),
                            'emotional_state' => $msg->emotional_state ?? 'neutral',
                            'conversation_level' => $msg->conversation_level ?? 'basic',
                        ];
                    });

                return response()->json([
                    'success' => true,
                    'data' => $messages,
                ], 200);
            } catch (\Exception $e) {
                Log::error('Error al obtener mensajes: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Sesión no encontrada',
                    'message' => $e->getMessage(),
                ], 404);
            }
        }

        public function summarizeConversation(Request $request)
        {
            $request->validate([
                'messages' => 'required|array',
                'messages.*.text' => 'required|string',
                'messages.*.is_user' => 'required|boolean',
                'messages.*.created_at' => 'required|date',
                'session_id' => 'nullable|exists:chat_sessions,id',
                'language' => 'nullable|string|in:es,en,fr,pt',
            ]);

            $this->initializeService($request);

            try {
                $messages = [];
                foreach ($request->messages as $msg) {
                    $messages[] = [
                        'role' => $msg['is_user'] ? 'user' : 'assistant',
                        'content' => $msg['text'],
                    ];
                }

                $summaryPrompt = $this->getSummaryPrompt($request->language);
                $messages[] = ['role' => 'system', 'content' => $summaryPrompt];

                $summary = $this->callOpenAIForSummary($messages);

                if (!is_string($summary) || empty($summary)) {
                    Log::warning('Respuesta de OpenAI para resumen inválida, usando mensaje por defecto.');
                    $summary = $this->getDefaultSummary($request->language);
                }

                return response()->json([
                    'success' => true,
                    'summary' => $summary,
                ], 200);
            } catch (\Exception $e) {
                Log::error('Error al resumir conversación: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Error al resumir la conversación',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        private function getSummaryPrompt($language)
        {
            switch ($language) {
                case 'en':
                    return "Summarize the following conversation in a concise and clear manner, capturing the main topics and emotions expressed. Keep the summary under 100 words.";
                case 'fr':
                    return "Résumez la conversation suivante de manière concise et claire, en capturant les principaux sujets et émotions exprimés. Gardez le résumé sous 100 mots.";
                case 'pt':
                    return "Resuma a seguinte conversa de forma concisa e clara, capturando os principais tópicos e emoções expressas. Mantenha o resumo com menos de 100 palavras.";
                default:
                    return "Resume la siguiente conversación de manera concisa y clara, capturando los temas principales y las emociones expresadas. Mantén el resumen en menos de 100 palabras.";
            }
        }

        private function getDefaultSummary($language)
        {
            switch ($language) {
                case 'en':
                    return "The conversation touched on various topics. Let's continue exploring what matters to you.";
                case 'fr':
                    return "La conversation a abordé divers sujets. Continuons à explorer ce qui vous importe.";
                case 'pt':
                    return "A conversa abordou vários tópicos. Vamos continuar explorando o que importa para você.";
                default:
                    return "La conversación abordó varios temas. Sigamos explorando lo que te importa.";
            }
        }

        private function callOpenAIForSummary($messages)
        {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                    'Content-Type' => 'application/json',
                ])->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => $messages,
                    'max_tokens' => 200,
                    'temperature' => 0.7,
                    'top_p' => 0.9,
                ]);

                if ($response->successful()) {
                    return $response->json()['choices'][0]['message']['content'];
                }

                Log::error('Error en OpenAI al resumir: ' . $response->body());
                return null;
            } catch (\Exception $e) {
                Log::error('Excepción en OpenAI al resumir: ' . $e->getMessage());
                return null;
            }
        }

        public function sendMessage(Request $request)
        {
            $request->validate([
                'message' => 'required|string',
                'session_id' => 'nullable|exists:chat_sessions,id',
                'is_temporary' => 'boolean',
                'language' => 'nullable|string|in:es,en,fr,pt',
                'user_name' => 'nullable|string|max:100',
            ]);

            $this->initializeService($request);

            try {
                $promptData = $this->lumorahService->generatePrompt($request->message);

                $contextMessages = [];
                if ($request->session_id && !$request->is_temporary) {
                    $contextMessages = $this->getConversationContext($request->session_id);
                }

                $aiResponse = $this->lumorahService->callOpenAI($request->message, $promptData['system_prompt'], $contextMessages);

                if (!is_string($aiResponse) || empty($aiResponse)) {
                    Log::warning('Respuesta de OpenAI inválida, usando mensaje por defecto.');
                    $aiResponse = $this->lumorahService->getDefaultResponse();
                }

                $sessionId = $request->session_id;

                if ($request->is_temporary) {
                    return response()->json([
                        'success' => true,
                        'ai_message' => [
                            'text' => $aiResponse,
                            'is_user' => false,
                            'emotional_state' => $promptData['emotional_state'],
                            'conversation_level' => $promptData['conversation_level'],
                        ],
                        'session_id' => null,
                    ], 200);
                }

                DB::transaction(function () use ($request, $aiResponse, &$sessionId, $promptData) {
                    if (!$sessionId) {
                        $sessionId = $this->createNewSession($request->message);
                    }

                    Message::create([
                        'chat_session_id' => $sessionId,
                        'user_id' => Auth::id(),
                        'text' => $request->message,
                        'is_user' => true,
                        'emotional_state' => $promptData['emotional_state'],
                        'conversation_level' => $promptData['conversation_level'],
                    ]);

                    Message::create([
                        'chat_session_id' => $sessionId,
                        'user_id' => null,
                        'text' => $aiResponse,
                        'is_user' => false,
                        'emotional_state' => $promptData['emotional_state'],
                        'conversation_level' => $promptData['conversation_level'],
                    ]);

                    $this->pusher->trigger('chat-channel', 'new-message', [
                        'session_id' => $sessionId,
                        'message' => $aiResponse,
                        'is_user' => false,
                    ]);
                });

                return response()->json([
                    'success' => true,
                    'ai_message' => [
                        'text' => $aiResponse,
                        'is_user' => false,
                        'emotional_state' => $promptData['emotional_state'],
                        'conversation_level' => $promptData['conversation_level'],
                    ],
                    'session_id' => $sessionId,
                ], 200);
            } catch (\Exception $e) {
                Log::error('Error en sendMessage: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Error al procesar el mensaje',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        public function sendTemporaryMessage(Request $request)
        {
            $request->validate([
                'message' => 'required|string',
                'language' => 'nullable|string|in:es,en,fr,pt',
            ]);

            $this->initializeService($request);

            try {
                $promptData = $this->lumorahService->generatePrompt($request->message);
                $aiResponse = $this->lumorahService->callOpenAI($request->message, $promptData['system_prompt']);

                if (!is_string($aiResponse) || empty($aiResponse)) {
                    Log::warning('Respuesta de OpenAI inválida, usando mensaje por defecto.');
                    $aiResponse = $this->lumorahService->getDefaultResponse();
                }

                return response()->json([
                    'success' => true,
                    'ai_message' => [
                        'text' => $aiResponse,
                        'is_user' => false,
                        'emotional_state' => $promptData['emotional_state'],
                        'conversation_level' => $promptData['conversation_level'],
                    ],
                ], 200);
            } catch (\Exception $e) {
                Log::error('Error en sendTemporaryMessage: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Error al procesar el mensaje temporal',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        public function startNewSession(Request $request)
        {
            $request->validate([
                'language' => 'nullable|string|in:es,en,fr,pt',
                'user_name' => 'nullable|string|max:100',
            ]);

            $this->initializeService($request);

            try {
                $session = ChatSession::create([
                    'user_id' => Auth::id(),
                    'title' => 'Nueva conversación',
                    'is_saved' => false,
                    'language' => $this->lumorahService->getUserLanguage(),
                    'user_name' => $this->lumorahService->getUserName() ?? $request->input('user_name'),
                ]);

                $welcomeMessage = $this->lumorahService->getWelcomeMessage();

                return response()->json([
                    'success' => true,
                    'session_id' => $session->id,
                    'ai_message' => [
                        'text' => $welcomeMessage,
                        'is_user' => false,
                        'emotional_state' => 'neutral',
                        'conversation_level' => 'basic',
                    ],
                ], 200);
            } catch (\Exception $e) {
                Log::error('Error al iniciar sesión: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Error al iniciar la conversación',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        public function updateUserName(Request $request)
        {
            $request->validate([
                'name' => 'required|string|max:100',
                'language' => 'nullable|string|in:es,en,fr,pt',
            ]);

            $this->initializeService($request);

            try {
                $usuario = Usuario::where('id', Auth::id())->first();
                if ($usuario) {
                    $usuario->update(['nombre' => $request->name]);
                }
                $this->lumorahService->setUserName($request->name);

                $responseMessage = $this->getUpdateNameResponse($request->language, $request->name);

                return response()->json([
                    'success' => true,
                    'ai_message' => [
                        'text' => $responseMessage,
                        'is_user' => false,
                        'emotional_state' => 'neutral',
                        'conversation_level' => 'basic',
                    ],
                ], 200);
            } catch (\Exception $e) {
                Log::error('Error al actualizar nombre: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Error al actualizar el nombre',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        public function sendVoiceMessage(Request $request)
{
    $request->validate([
        'message' => 'required|string',
        'session_id' => 'nullable|exists:chat_sessions,id',
        'language' => 'nullable|string|in:es,en,fr,pt',
        'user_name' => 'nullable|string|max:100',
    ]);

    try {
        // Inicializa el servicio de voz
        $voiceService = new LumorahAIService(
            $request->user_name,
            $request->language ?? 'es'
        );

        // Genera el prompt optimizado para voz
        $promptData = $voiceService->generateVoicePrompt($request->message);

        // Obtiene contexto si hay session_id
        $contextMessages = [];
        if ($request->session_id) {
            $contextMessages = $this->getConversationContext($request->session_id);
        }

        // Llama a OpenAI
        $aiResponse = $voiceService->callOpenAI(
            $request->message, 
            $promptData['system_prompt'], 
            $contextMessages
        );

        // Formatea la respuesta para voz
        $formattedResponse = $voiceService->formatVoiceResponse($aiResponse);

        // Guarda en base de datos si hay session_id
        $sessionId = $request->session_id;
        if ($sessionId) {
            DB::transaction(function () use ($request, $formattedResponse, $sessionId, $promptData) {
                Message::create([
                    'chat_session_id' => $sessionId,
                    'user_id' => Auth::id(),
                    'text' => $request->message,
                    'is_user' => true,
                    'emotional_state' => $promptData['emotional_state'],
                    'conversation_level' => $promptData['conversation_level'],
                ]);

                Message::create([
                    'chat_session_id' => $sessionId,
                    'user_id' => null,
                    'text' => $formattedResponse,
                    'is_user' => false,
                    'emotional_state' => $promptData['emotional_state'],
                    'conversation_level' => $promptData['conversation_level'],
                ]);

                $this->pusher->trigger('chat-channel', 'new-message', [
                    'session_id' => $sessionId,
                    'message' => $formattedResponse,
                    'is_user' => false,
                ]);
            });
        }

        return response()->json([
            'success' => true,
            'ai_message' => [
                'text' => $formattedResponse,
                'is_user' => false,
                'emotional_state' => $promptData['emotional_state'],
                'conversation_level' => $promptData['conversation_level'],
            ],
            'session_id' => $sessionId,
        ], 200);

    } catch (\Exception $e) {
        Log::error('Error en sendVoiceMessage: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'error' => 'Error al procesar el mensaje de voz',
            'message' => $e->getMessage(),
        ], 500);
    }
}



        private function getUpdateNameResponse($language, $name)
        {
            switch ($language) {
                case 'en':
                    return "Thank you, $name. Now that we know each other better, what would you like to share today?";
                case 'fr':
                    return "Merci, $name. Maintenant que nous nous connaissons mieux, que souhaitez-vous partager aujourd'hui ?";
                case 'pt':
                    return "Obrigado, $name. Agora que nos conhecemos melhor, o que você gostaria de compartilhar hoje?";
                default:
                    return "Gracias, $name. Ahora que nos conocemos mejor, ¿qué te gustaría compartir hoy?";
            }
        }

        protected function getConversationContext($sessionId)
        {
            return Message::where('chat_session_id', $sessionId)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($message) {
                    return [
                        'text' => $message->text,
                        'is_user' => $message->is_user,
                    ];
                })
                ->toArray();
        }

        protected function createNewSession($firstMessage)
        {
            $session = ChatSession::create([
                'user_id' => Auth::id(),
                'title' => $this->generateSessionTitle($firstMessage),
                'is_saved' => false,
                'language' => $this->lumorahService->getUserLanguage(),
                'user_name' => $this->lumorahService->getUserName() ?? Auth::user() ? Usuario::where('id', Auth::id())->first()->nombre : null,
            ]);

            return $session->id;
        }
    }