<?php

namespace App\Http\Controllers;


use App\Helpers\Lyn;
use GuzzleHttp\Client;
use App\Models\History;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Exception\RequestException;

class SingleSender extends Controller
{
    public function __construct()
    {
        $this->url = config('app.base_node');
    }

    public function index()
    {
        if (!session()->get('main_device')) {
            return Lyn::view('nodevice');
        }
        return Lyn::view('singlesend.index');
    }

    public function store(Request $request)
    {
        if (!session()->get('main_device')) return response()->json(['message' => 'No device selected.'], 400);
        $request->validate([
            'receiver' => 'required',
            'message_type' => 'required',
        ]);

        $device = Session::where(['id' => session()->get('main_device'), 'user_id' => auth()->user()->id])->first();

        if (!$device) return response()->json(['message' => 'No device selected.']);


        $pars = array(
            "api_key" => $device->api_key,
            "receiver" => $this->validate_receiver("$request->receiver"),
        );

        if ($request->message_type == 'text') {

            $request->validate([
                'message' => 'required',
            ]);

            $pars['data'] = array(
                'message' => $request->message,
            );

            try {
                // Inicializa el cliente Guzzle
                $client = new Client();

                // Realiza la solicitud POST con Guzzle
                $response = $client->post($this->url . '/api/send-message', [
                    'json' => $pars, // Puedes cambiar 'json' a 'form_params' si necesitas enviar datos como formulario
                ]);

                // Obtén el cuerpo de la respuesta
                $responseBody = json_decode($response->getBody()->getContents(), true);

                // Log para ver los datos enviados y la respuesta recibida
                Log::info('Datos enviados:', ['pars' => $pars]);
                Log::info('Respuesta recibida:', ['response' => $responseBody]);

                // Si la respuesta es exitosa
                if (isset($responseBody['status']) && $responseBody['status'] == 'success') {
                    History::record($request, [
                        'from' => 'single',
                        'status' => 'sent'
                    ]);
                    return response()->json(['message' => 'Message sent.']);
                } else {
                    // Si hay un error en la respuesta
                    History::record($request, [
                        'from' => 'single',
                        'status' => 'failed'
                    ]);
                    return response()->json(['message' => ($responseBody['message'] ?? 'Failed to send message.')], 500);
                }
            } catch (RequestException $e) {
                // Captura cualquier excepción de Guzzle
                Log::error('Error al enviar el mensaje:', ['error' => $e->getMessage()]);
                History::record($request, [
                    'from' => 'single',
                    'status' => 'failed'
                ]);
                return response()->json(['message' => $e->getMessage()], 500);
            } catch (\Exception $e) {
                // Captura otras excepciones
                \Log::error('Error general:', ['error' => $e->getMessage()]);
                History::record($request, [
                    'from' => 'single',
                    'status' => 'failed'
                ]);
                return response()->json(['message' => $e->getMessage()], 500);
            }
        } else if ($request->message_type == 'media') {
            $request->validate([
                'media' => 'required',
                'media_type' => 'required',
            ]);
            $pars['waiting'] = 3000;
            $pars['data'] = array(
                'url' => $request->media,
                'media_type' => $request->media_type,
                'caption' => $request->message ?? '',
            );
            try {
                $response = Http::post($this->url . '/api/send-media', $pars);
                $response = $response->json();
                if ($response['status'] == 'success') {
                    History::record($request, [
                        'from' => 'single',
                        'status' => 'sent'
                    ]);
                    return response()->json(['message' => ($response['message'] ?? 'Media sent.')]);
                } else {
                    History::record($request, [
                        'from' => 'single',
                        'status' => 'failed'
                    ]);
                    return response()->json(['message' => ($response['message'] ?? 'Failed to send message.')], 500);
                }
            } catch (\Exception $e) {
                History::record($request, [
                    'from' => 'single',
                    'status' => 'failed'
                ]);
                return response()->json(['message' => $e->getMessage()], 500);
            }
        } else if ($request->message_type == 'button') {
            $request->validate([
                'message' => 'required',
                'footer' => 'required',
            ]);

            $buttons = [];

            foreach ($request->btn_display as $key => $val) {
                $buttons[] = array(
                    "display" => $request->btn_display[$key],
                    "id" => $request->btn_id[$key],
                );
            }

            $pars['data'] = array(
                'message' => $request->message,
                'footer' => $request->footer,
                'buttons' => $buttons,
            );

            try {
                $response = Http::post($this->url . '/api/send-button', $pars);
                $response = $response->json();
                if ($response['status'] == 'success') {
                    History::record($request, [
                        'from' => 'single',
                        'status' => 'sent'
                    ]);
                    return response()->json(['message' => ($response['message'] ?? 'Media sent.')]);
                } else {
                    History::record($request, [
                        'from' => 'single',
                        'status' => 'failed'
                    ]);
                    return response()->json(['message' => ($response['message'] ?? 'Failed to send message.')], 500);
                }
            } catch (\Exception $e) {
                History::record($request, [
                    'from' => 'single',
                    'status' => 'failed'
                ]);
                return response()->json(['message' => $e->getMessage()], 500);
            }
        } else if ($request->message_type == 'list') {
            $request->validate([
                'message' => 'required',
                'footer' => 'required',
            ]);

            $sections = [];
            $first = true;
            foreach ($request->btn_display as $key => $val) {
                if ($request->type[$key] == 'section') {
                    if ($first) {
                        $first = false;
                    }
                    $sections[] = array(
                        "title" => $request->btn_display[$key],
                        "rows" => [],
                    );
                } else if ($request->type[$key] == 'option') {
                    if ($first) {
                        $sections[] = array(
                            "rows" => [],
                        );
                        $first = false;
                    }
                    $sections[count($sections) - 1]['rows'][] = array(
                        "title" => $request->btn_display[$key],
                        "rowId" => $request->btn_id[$key] ?? '',
                    );
                }
            }

            $pars['data'] = array(
                'title' => $request->title ?? '',
                'message' => $request->message,
                'footer' => $request->footer,
                'buttonText' => $request->button_text ?? 'Click Here',
                'sections' => $sections,
            );

            try {
                $response = Http::post($this->url . '/api/send-listmsg', $pars);
                $response = $response->json();
                if ($response['status'] == 'success') {
                    History::record($request, [
                        'from' => 'single',
                        'status' => 'sent'
                    ]);
                    return response()->json(['message' => ($response['message'] ?? 'List Button sent.')]);
                } else {
                    History::record($request, [
                        'from' => 'single',
                        'status' => 'failed'
                    ]);
                    return response()->json(['message' => ($response['message'] ?? 'Failed to send List Button.')], 500);
                }
            } catch (\Exception $e) {
                History::record($request, [
                    'from' => 'single',
                    'status' => 'failed'
                ]);
                return response()->json(['message' => $e->getMessage()], 500);
            }
        }
    }


    public function validate_receiver($number)
    {
        if (!strpos($number, '@g.us')) {
            return trim(preg_replace('/[^0-9]/', '', $number));
        } else {
            return trim($number);
        }
    }
}
