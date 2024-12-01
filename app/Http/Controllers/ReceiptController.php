<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use Illuminate\Http\Request;

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Image;

class ReceiptController extends Controller
{
    public function index()
    {
        //$receipts = auth()->user()->receipts()->latest()->paginate(10);
        //return view('receipts.index', compact('receipts'));
        return redirect()->route('receipts.create');
    }

    public function create()
    {
        return view('receipts.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'image' => 'required|image|max:5120', // 5MB max
        ]);

        try {
            // Prepare credentials array
            $credentials = [
                "type" => env('GOOGLE_VISION_TYPE'),
                "project_id" => env('GOOGLE_VISION_PROJECT_ID'),
                "private_key_id" => env('GOOGLE_VISION_PRIVATE_KEY_ID'),
                "private_key" => env('GOOGLE_VISION_PRIVATE_KEY'),
                "client_email" => env('GOOGLE_VISION_CLIENT_EMAIL'),
                "client_id" => env('GOOGLE_VISION_CLIENT_ID'),
                "auth_uri" => "https://accounts.google.com/o/oauth2/auth",
                "token_uri" => "https://oauth2.googleapis.com/token",
                "auth_provider_x509_cert_url" => "https://www.googleapis.com/oauth2/v1/certs",
                "client_x509_cert_url" => env('GOOGLE_VISION_CERT_URL')
            ];

            // Get image content
            $imageContent = file_get_contents($request->file('image')->path());
            
            // Initialize Vision API client
            $imageAnnotator = new ImageAnnotatorClient([
                'credentials' => $credentials
            ]);
            
            // Create image object
            $image = (new Image())->setContent($imageContent);
            
            // Perform text detection
            $response = $imageAnnotator->textDetection($image);
            $texts = $response->getTextAnnotations();
            
            // Get full text
            $extractedText = '';
            if ($texts->count() > 0) {
                $extractedText = $texts[0]->getDescription();
            }
            
            // Close the client
            $imageAnnotator->close();

            // past to AI
            $structuredData = $this->processWithAIHaiku($extractedText);
            //$structuredData = $this->processWithAILlama($extractedText);

            dd($structuredData);

            // Store in session for display
            return back()
                ->with('status', 'receipt-processed')
                ->with('extractedText', $extractedText);

        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to process image: ' . $e->getMessage()]);
        }

    }

    protected function processWithAIHaiku($text)
    {
        $url = 'https://api.anthropic.com/v1/messages';
        $apiKey = env('ANTHROPIC_API_KEY');

        $data = [
            'model' => 'claude-3-haiku-20240307',
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "Given the following raw text extracted from a receipt, please analyze and structure it into a JSON format with the following fields:
                    - merchant_name: The name of the store/business
                    - date: The receipt date
                    - total_amount: The total amount paid
                    - items: Array of items purchased with their prices
                    - tax_amount: Tax amount if present
                    - payment_method: Payment method if mentioned

                    Raw receipt text:
                    --------------------------------------------------
                    $text
                    --------------------------------------------------

                    Please return only the JSON structure without any additional text or explanation."
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01'
            ]
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \Exception('cURL Error: ' . $err);
        }

        $result = json_decode($response, true);
        return json_decode($result['content'][0]['text'], true);
    }

    protected function processWithAILlama($text)
    {
        $url = 'https://api.together.xyz/v1/chat/completions';
        $apiKey = env('TOGETHER_API_KEY');

        $data = [
            'model' => 'meta-llama/Llama-Vision-Free',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Given the following raw text extracted from a receipt, please analyze and structure it into a JSON format with the following fields:
                            - merchant_name: The name of the store/business
                            - date: The receipt date
                            - total_amount: The total amount paid
                            - items: Array of items purchased with their prices
                            - tax_amount: Tax amount if present
                            - payment_method: Payment method if mentioned

                            Raw receipt text:
                            --------------------------------------------------
                            $text
                            --------------------------------------------------

                            Please return only the JSON structure without any additional text or explanation."
                        ]
                    ]
                ]
            ],
            'max_tokens' => null,
            'temperature' => 0.7,
            'top_p' => 0.7,
            'top_k' => 50,
            'repetition_penalty' => 1,
            'stop' => ['<|eot_id|>', '<|eom_id|>'],
            'stream' => false
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \Exception('cURL Error: ' . $err);
        }

        $result = json_decode($response, true);
        
        // Get the content from the chat completion response
        $jsonText = $result['choices'][0]['message']['content'];
        
        // Clean the response to ensure it's valid JSON
        $jsonText = preg_replace('/```json\s*|\s*```/', '', $jsonText);
        
        return json_decode($jsonText, true);
    }

    // Add other methods as needed...
}