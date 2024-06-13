<?php
require 'vendor/autoload.php'; // Composer autoload for dependencies
use GuzzleHttp\Client;

$verify_token = 'YOUR_VERIFY_TOKEN';
$access_token = 'YOUR_PAGE_ACCESS_TOKEN';
$dialogflow_project_id = 'YOUR_DIALOGFLOW_PROJECT_ID';
$openai_api_key = 'YOUR_OPENAI_API_KEY';

function verifyToken($verify_token) {
    if ($_GET['hub_verify_token'] === $verify_token) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        echo "Invalid Verify Token";
        exit;
    }
}

function sendMessageToFacebook($recipientId, $message) {
    global $access_token;
    $client = new Client();
    $response = $client->post("https://graph.facebook.com/v8.0/me/messages?access_token=$access_token", [
        'json' => [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $message]
        ]
    ]);
}

function handleDialogflowRequest($query, $sessionId) {
    global $dialogflow_project_id, $openai_api_key;
    $client = new Client();
    $response = $client->post("https://dialogflow.googleapis.com/v2/projects/$dialogflow_project_id/agent/sessions/$sessionId:detectIntent", [
        'json' => [
            'queryInput' => [
                'text' => [
                    'text' => $query,
                    'languageCode' => 'en'
                ]
            ]
        ]
    ]);

    $dialogflowResponse = json_decode($response->getBody(), true);
    $fulfillmentText = $dialogflowResponse['queryResult']['fulfillmentText'] ?? '';

    // If Dialogflow doesn't provide a satisfactory response, use OpenAI
    if (empty($fulfillmentText)) {
        $response = $client->post('https://api.openai.com/v1/engines/davinci-codex/completions', [
            'headers' => [
                'Authorization' => "Bearer $openai_api_key"
            ],
            'json' => [
                'prompt' => $query,
                'max_tokens' => 150
            ]
        ]);
        $openAiResponse = json_decode($response->getBody(), true);
        $fulfillmentText = $openAiResponse['choices'][0]['text'] ?? 'Sorry, I couldn\'t understand that.';
    }

    return $fulfillmentText;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    verifyToken($verify_token);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    foreach ($input['entry'] as $entry) {
        foreach ($entry['messaging'] as $event) {
            if (!empty($event['message']['text'])) {
                $senderId = $event['sender']['id'];
                $messageText = $event['message']['text'];

                $responseText = handleDialogflowRequest($messageText, $senderId);

                sendMessageToFacebook($senderId, $responseText);
            }
        }
    }
}
?>