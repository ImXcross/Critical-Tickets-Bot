<?php

chdir('/var/www/xxxxxxxxxxxx/test/meta_ats_critical/');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_NOTICE);

include("logging.class.php");
$log = new Logging();
$log->info("================================ START ================================");

$api_url = "https://helpdesk.xxxxxxx.com/apirest.php";
$app_token = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxx";
$user_token = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxVrYmN";
$accessToken = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx";


function checkAllTicketStatus($sessionToken, $appToken, $apiUrl, $pdo, $log) {
    try {
        // Obtener todos los ticket_id de la base de datos
        $query = $pdo->query("SELECT ticket_id FROM dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL");
        $ticketIds = $query->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ticketIds as $ticketId) {
            // Verificar el estado del ticket
            $url = $apiUrl . "/Ticket/" . $ticketId;
            $headers = [
                "Session-Token: $sessionToken",
                "App-Token: $appToken"
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $log->info("Checking the status of the ticket with ID: $ticketId...");

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                $log->info('cURL error in checkAllTicketStatus: ' . curl_error($ch));
                continue; // Pasar al siguiente ticket
            }
            curl_close($ch);

            $log->info("checkAllTicketStatus response for ticket with ID: $ticketId: " . $response);

            $data = json_decode($response, true);
            $log->info("Ticket status with ID: $ticketId: " . $data['status']);

            if (isset($data['status']) && ($data['status'] === 'closed' || !empty($data['closedate']))) {
                $log->info("Ticket with ID $ticketId is closed");
                // Eliminar la fila de la base de datos relacionada con este ticket_id
                $deleteQuery = $pdo->prepare("DELETE FROM dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL WHERE ticket_id = :ticketId");
                $deleteQuery->bindParam(':ticketId', $ticketId);
                $deleteQuery->execute();
                $log->info("The database row for the ticket with ID has been deleted $ticketId");
            } else {
                $log->info("Ticket with ID $ticketId is not closed");
            }
        }

    } catch (PDOException $e) {
        $log->error("Error getting ticket_id from database: " . $e->getMessage());
    }
}



function getGlpiSessionToken($api_url, $app_token, $user_token) {
    global $log;
    $url = $api_url . "/initSession";
    $headers = [
        "App-Token: $app_token",
        "Authorization: user_token $user_token",
        'Content-Type: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, false);

    $log->info("Requesting GLPI session token...");

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $log->info('cURL error in getGlpiSessionToken: ' . curl_error($ch));
        return null;
    }
    curl_close($ch);

    $log->info("Response from getGlpiSessionToken: " . $response);

    $data = json_decode($response, true);
    if (isset($data['session_token'])) {
        echo ("Obtained GLPI session token: " . $data['session_token']);
        return $data['session_token'];
    } else {
        echo ("Failed to get GLPI session token, response: " . $response);
        return null;
    }
}

try {
    $pdo = new PDO('dblib:host=xxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'glpi', 'xxxxxxxxxxxxxxxxxxxxxxx', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $log->info("Successful connection to SQL database");
} catch (PDOException $e) {
    $log->error("Error connecting to SQL database: " . $e->getMessage());
    exit;
}

$sessionToken = getGlpiSessionToken($api_url, $app_token, $user_token);

try {
    checkAllTicketStatus($sessionToken, $app_token, $api_url, $pdo, $log);
} catch (PDOException $e) {
    $log->error("Error checking ticket status: " . $e->getMessage());
}

try {
    $consulta = $pdo->query("SELECT ticket_id FROM dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL");
    $ids = $consulta->fetchAll(PDO::FETCH_COLUMN);
    $log->info("SQL query executed successfully, obtained:" . count($ids) . " IDs");

    foreach ($ids as $id) {
        echo ("Obtained IDs: $id<br>");
        
        $url = $api_url . "/Ticket/" . $id . "/TicketFollowup/";
        $headers = [
            "Content-Type: application/json",
            "Session-Token: $sessionToken",
            "App-Token: $app_token"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        var_dump($response);
        
        if (curl_errno($ch)) {
            echo 'cURL error in updateGlpiTicket: ' . curl_error($ch);
        } else {
            echo "Response from updateGlpiTicket: " . $response;
        }
        curl_close($ch);

        $responseData = json_decode($response, true);

        echo "<pre>";
        var_dump($responseData);
        echo "</pre>";

        $phoneNumber = "";
        try {
            $phoneNumberQuery = $pdo->prepare("SELECT wamid FROM dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL WHERE ticket_id = ?");
            $phoneNumberQuery->execute([$id]);
            $phoneNumber = $phoneNumberQuery->fetchColumn();
            echo ("Phone number: $phoneNumber<br>");
        } catch (PDOException $e) {
            echo ("Error getting phone number: " . $e->getMessage());
            exit;
        }

        $messageContent = [];
        foreach ($responseData as $response) {
            $messageDateMod = strtotime($response['date_mod']);
            $lastSentDateDB = strtotime($pdo->query("SELECT MAX(date_sent) AS max_date FROM dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL")->fetchColumn());
            if ($messageDateMod > $lastSentDateDB) {
                $messageContent[] = strip_tags(html_entity_decode($response['content']));
            }
        }
        

        $messageContent = implode("\n", $messageContent);

        if (!empty($messageContent)) {
            sendMessage($phoneNumber, $messageContent, $responseData, $pdo, $log);  // Ensure that sendMessage now also takes $pdo and $log as parameters
            $log->info("Message sent to $phoneNumber: $messageContent");
            echo "Message sent to $phoneNumber: $messageContent";
        } else {
            echo "There are no new messages to send.";
        }
    }

} catch (PDOException $e) {
    echo ("Error executing SQL query: " . $e->getMessage());
    exit;
}

function sendMessage($phoneNumber, $messageContent, $responseData, $pdo, $log) {
    if (strpos($phoneNumber, '+') !== 0) {
        $phoneNumber = '+' . $phoneNumber;
    }

    $url = "https://graph.facebook.com/v18.0/xxxxxxxxxxxxxxxxxxxxxxx";
    $data = [
        'messaging_product' => 'whatsapp',
        'to' => $phoneNumber,
        'type' => 'text',
        'text' => ['body' => $messageContent]
    ];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $log->info("Sending message to WhatsApp...");

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $log->info('cURL error: ' . curl_error($ch));
    } else {
        $log->info("Response from sendMessage: " . $response);
    }
    curl_close($ch);

    // Actualizar la fecha de la base de datos con la fecha del mensaje más reciente
    if (!empty($messageContent)) {
        $latestMessageDate = '';
        foreach ($responseData as $message) {
            if (strtotime($message['date_mod']) > strtotime($latestMessageDate)) {
                $latestMessageDate = $message['date_mod'];
            }
        }

        if ($latestMessageDate) {
            try {
                $log->info("latestmessageDate: " . $latestMessageDate);

                $phoneNumber = ltrim($phoneNumber, '+');
        
                $sqlUpdate = "UPDATE dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL 
                              SET date_sent = :latestmessageDate
                              WHERE wamid = :phoneNumber";
                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->bindParam(':latestmessageDate', $latestMessageDate);
                $stmtUpdate->bindParam(':phoneNumber', $phoneNumber);
                if ($stmtUpdate->execute()) {
                    $log->info("date_sent updated in database for number $phoneNumber");
                } else {
                    $log->error("Error updating date_sent in database for number $phoneNumber");
                }
            } catch (PDOException $e) {
                $log->error("Error running SQL query to update date_sent: " . $e->getMessage());
            }
        }
         else {
            $log->error("No messages found to update date_sent");
        }
    } else {
        $log->error("Message content is empty");
    }
}

$log->info("================================ END ================================");
?>