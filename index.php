<?php
chdir('/var/www/xxxxxxxxxxxxxxxxxxxxx/test/meta_ats_critical/');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_NOTICE);

include("logging.class.php");
$log = new Logging();
$log->info("================================ START ================================");


$arrayAllowedNumbers = array(
    '+49123123123' => array('652', 'Name1'),
    '+491212121212' => array('52', 'Name2'),
    '+49121212121212' => array('104', 'Name3')
); 

// URL API and Tokens IMPORTANT!!!
$api_url = "https://helpdesk.xxxxxxxxxxxxxxxxxxxx/apirest.php";
$app_token = "xxxxxxxxxxxxxxxxxxxxxxxxx";
$user_token = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx";
$accessToken = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx";

function isNumberAllowed($number) {
    $number = addPlusIfNeeded($number);
    global $arrayAllowedNumbers;
    if (array_key_exists($number, $arrayAllowedNumbers)) {
        return true;

    } else {
        return false;
    }
}

function addPlusIfNeeded($line) {
    if (substr($line, 0, 1) !== '+') {
        $line = '+' . $line;
    }
    return $line;
}

function removePlusIfNeeded($line) {
    if (substr($line, 0, 1) === '+') {
        $line = substr($line, 1);
    }
    return $line;
}

    function sendMessage($recipientId, $text, $buttons = null) {
        global $log;

        $url = "https://graph.facebook.com/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx";
        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientId,
        ];

        // Agregar texto al mensaje
        $data['type'] = 'text';
        $data['text'] = ['body' => $text];


    // Agregar texto al mensaje
    if (is_null($buttons)) {
        $data['type'] = 'text';
        $data['text'] = ['body' => $text];
    } else {
        // Asumiendo que $buttons ya está en el formato correcto
        $data['type'] = 'interactive';
        $data['interactive'] = [
            'type' => 'button',
            'body' => [
                'text' => $text,
            ],
            'action' => [
                'buttons' => array_map(function ($button) {
                    return [
                        'type' => 'reply',
                        'reply' => [
                            'id' => $button['payload'],
                            'title' => $button['title']
                        ]
                    ];
                }, $buttons)
            ],
        ];
    }

        // Configurar los encabezados y el cuerpo de la solicitud
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
        ];

        // Realizar la solicitud HTTP usando cURL
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
            $log->info("Obtained GLPI session token: " . $data['session_token']);
            return $data['session_token'];
        } else {
            $log->info("Failure to obtain GLPI session token, response: " . $response);
            return null;
        }
    }

    function createGlpiTicket($api_url, $session_token, $app_token, $ticket_details) {
        global $log;
        $url = $api_url . "/Ticket";
        $headers = [
            "Session-Token: $session_token",
            "App-Token: $app_token",
            'Content-Type: application/json'
        ];
        
        $requesterId= $arrayAllowedNumbers[$senderId][0];

        // Agregar el ID del requester al arreglo de detalles del ticket
        $ticket_details['input']['users_id_recipient'] = $requesterId;


        $data = json_encode(['input' => $ticket_details]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        $log->info("Creatin ticket in GLPI...");

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $log->info('cURL error in createGlpiTicket: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);

        $log->info("Response from createGlpiTicket: " . $response);

        $responseData = json_decode($response, true);
        if (isset($responseData['id'])) {
            $log->info("Ticket created with ID: " . $responseData['id']);
            return $responseData['id']; // Devuelve el ID real del ticket creado
        } else {
            $log->info("Error creating ticket. Response: " . $response);
            return false;
        }
    }

    function checkOpenTicketByUser($api_url, $session_token, $app_token, $userId) {
        $url = $api_url . "/Ticket";

        $params = [
            'criteria' => [
                '0' => [
                    'field' => 'users_id_requester',
                    'searchtype' => 'equals',
                    'value' => $userId
                ],
                '1' => [
                    'link' => 'AND',
                    'field' => 'status',
                    'searchtype' => 'equals',
                    'value' => 1 // 1 para tickets abiertos
                ]
            ]
        ];

        $headers = [
            "Content-Type: application/json",
            "Session-Token: $session_token",
            "App-Token: $app_token"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $responseData = json_decode($response, true);
        
        // Verifica si hay algún ticket abierto con el usuario dado
        if (!empty($responseData['data'])) {
            return true; // Hay al menos un ticket abierto con el usuario dado
        } else {
            return false; // No hay ningún ticket abierto con el usuario dado
        }
    }


    function updateGlpiTicket($api_url, $session_token, $app_token, $ticketId, $message, $mediaId = null) {
        global $log, $pdo, $senderId;

        // Consulta SQL para verificar si el número del remitente coincide con alguna línea en la tabla
        $sql_check_sender = "SELECT ticket_id FROM dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL WHERE wamid = :senderId";
        $stmt_check_sender = $pdo->prepare($sql_check_sender);
        $stmt_check_sender->bindParam(':senderId', $senderId);
        $stmt_check_sender->execute();
        $result_check_sender = $stmt_check_sender->fetch(PDO::FETCH_ASSOC);

        if ($result_check_sender) {
            // Si el número del remitente coincide con alguna línea en la tabla, se obtiene el ticket_id
            $ticketId = $result_check_sender['ticket_id'];

            $url = $api_url . "/Ticket/" . $ticketId . "/TicketFollowup";

            $data = [
                "input" => [
                    "tickets_id" => $ticketId,
                    "content" => $message,
                ]
            ];

            // Verificar si hay una imagen adjunta y agregar el MEDIA_ID a los detalles del ticket
            if ($mediaId !== null) {
                $data['input']['media_id'] = $mediaId;
            } else {
                // Si no hay MEDIA_ID pero no hay contenido de texto, proporciona una descripción predeterminada
                if (empty($message)) {
                    $message = "Documents added to the ticket";
                    $data['input']['content'] = $message;
                }
            }

            // Definir los encabezados para la solicitud HTTP
            $headers = [
                "Content-Type: application/json",
                "Session-Token: $session_token",
                "App-Token: $app_token",  
            ];

            $log->info('Data to be sent: ' . json_encode($data));

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                $log->info('cURL error in updateGlpiTicket: ' . curl_error($ch));
                echo 'cURL error in updateGlpiTicket: ' . curl_error($ch);
            } else {
                $log->info("Response from updateGlpiTicket: " . $response);
                echo "Response from updateGlpiTicket: " . $response;
            }
            curl_close($ch);

            $responseData = json_decode($response, true);

            if (isset($responseData['id'])) {
                $log->info("Tracking added to ticket with ID: " . $responseData['id']);
                return true; // Seguimiento añadido con éxito
            } else {
                $log->info("Error adding tracking to ticket. Response: " . $response);
                echo "Error adding tracking to ticket. Response: " . $response;
                return false; // Fallo al añadir seguimiento
            }
        } else {
            // Si el número del remitente no coincide con ninguna línea en la tabla
            $log->info("The sender number does not match any line in the DB.");
            return false;
        }
    }

    function associateFileToTicket($api_url, $session_token, $app_token, $ticketId, $message, $documentId, $mediaId) {
        global $log, $pdo, $senderId;
        // Consulta SQL para verificar si el número del remitente coincide con alguna línea en la tabla
        $sql_check_sender = "SELECT ticket_id FROM dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL WHERE wamid = :senderId";
        $stmt_check_sender = $pdo->prepare($sql_check_sender);
        $stmt_check_sender->bindParam(':senderId', $senderId);
        $stmt_check_sender->execute();
        $result_check_sender = $stmt_check_sender->fetch(PDO::FETCH_ASSOC);

        if ($result_check_sender) {
            // Si el número del remitente coincide con alguna línea en la tabla, se obtiene el ticket_id
            $ticketId = $result_check_sender['ticket_id'];

            $log->info('Ticket ID: ' . $ticketId);

            $url = $api_url . "/Document_Item";

            $data = [
                'input' => [
                    'documents_id' => $documentId, // ID del documento
                    'itemtype' => 'Ticket', // Tipo de elemento a asociar, en este caso, un Documento
                    'items_id' => $ticketId, // ID del ticket
                ]
            ];

            $headers = [
                "Content-Type: application/json",
                "Session-Token: $session_token",
                "App-Token: $app_token"
            ];
        
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                $log->info('cURL error in associateTicketToDocument: ' . curl_error($ch));
                return false;
            }
            curl_close($ch);
        
            $responseData = json_decode($response, true);
            if (isset($responseData['id'])) {
                $log->info("Ticket associated with the document successfully. Document_Item ID: " . $responseData['id']);
                return true;
            } else {
                $log->info("Error when associating the ticket with the document. Response: " . $response);
                return false;
            }
        }
    }

    function getImageUrlFromMediaId($mediaId) {
        global $log;

        // Tu token de acceso
        $accessToken = "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx";

        $url = "https://graph.facebook.com/v19.0/$mediaId?fields=url&access_token=$accessToken";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['url'])) {
            return $data['url'];
        } else {
            $log->info("Could not get the image URL. Response: " . $response);
            return null;
        }
    }

    function downloadMedia($mediaUrl, $savePath) {
        global $log, $accessToken;

        // Inicializa cURL
        $ch = curl_init();
        
        // Configura las opciones de cURL
        curl_setopt($ch, CURLOPT_URL, $mediaUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: curl/7.64.1',
            "Authorization: Bearer " . $accessToken
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Seguir redirecciones

        $log->info("Mediaurl inside downloadMedia: " . $mediaUrl);

        // Ejecuta la solicitud cURL
        $mediaContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        // Verifica si la solicitud fue exitosa y el contenido es una imagen
        if ($httpCode == 200 && strpos($contentType, 'image/') === 0) {
            // Determina la extensión basada en el contentType
            $extension = '';
            if ($contentType === 'image/jpeg') {
                $extension = '.jpg';
            } elseif ($contentType === 'image/png') {
                $extension = '.png';
            }
            // Añade la extensión al path donde se guardará la imagen
            $fullPath = $savePath . $extension;

            // Guarda el contenido en un archivo
            if (file_put_contents($fullPath, $mediaContent)) {
                $log->info("Media downloaded successfully: " . $fullPath);
                return $fullPath;
            } else {
                $log->info("Failed to save the media.");
                return false;
            }
        } else {
            $log->info("Failed to download the media or content type is not an image. HTTP status code: " . $httpCode . ". Content type: " . $contentType);
            return false;
        }
    }

    function uploadFileToGlpi($api_url, $session_token, $app_token, $filePath) {
        global $log;

        $log->info("Starting function uploadFileToGlpi...");
        $log->info("URL of GLPI: " . $url);
        $log->info("Session Token: " . $session_token);
        $log->info("App Token: " . $app_token);
        $log->info("File Path: " . $filePath);

        // La URL para subir un documento a GLPI
        $url = $api_url . "/Document";

        // Prepara el archivo para cargar
        $cfile = new CURLFile($filePath, 'image/jpeg', basename($filePath)); 
        $log->info("CURLFile creado.");

        // Prepara los datos para la petición
        $postData = [
            'uploadManifest' => json_encode([
                'input' => [
                    '_filename' => [basename($filePath)],
                ],
            ]),
            'filename[0]' => $cfile,
        ];

        $log->info("Data request. Prepared.");

        $headers = [
            "Content-Type: multipart/form-data",
            "Session-Token: $session_token",
            "App-Token: $app_token" // Incluye el app_token aquí
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $log->info("Making cURL request to GLPI...");

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $log->info("cURL error in uploadFileToGlpi: " . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);
        
        $responseData = json_decode($response, true);
        if (isset($responseData['id'])) {
            $log->info("File uploaded successfully. Document ID: " . $responseData['id']);
            return $responseData['id']; // Retorna el ID del documento subido
        } else {
            $log->info("Error uploading file to GLPI. Response: " . json_encode($responseData));
            return false;
        }
    }


    // Funciones para manejar el estado y los datos
    function obtenerEstado($senderId) {
        $archivoEstado = "/tmp/estado_$senderId.txt";
        if (file_exists($archivoEstado)) {
            return file_get_contents($archivoEstado);
        }
        return false;
    }

    function guardarEstado($senderId, $estado) {
        $archivoEstado = "/tmp/estado_$senderId.txt";
        file_put_contents($archivoEstado, $estado);
    }

    function limpiarEstado($senderId) {
        $archivoEstado = "/tmp/estado_$senderId.txt";
        if (file_exists($archivoEstado)) {
            unlink($archivoEstado);
        }
    }

    function guardarDatos($senderId, $estado, $ticketId = null) {
        $archivoDatos = "/tmp/datos_$senderId.json";
        $datos = ['estado' => $estado, 'ticketId' => $ticketId];
        file_put_contents($archivoDatos, json_encode($datos));
    }

    function obtenerDatos($senderId) {
        $archivoDatos = "/tmp/datos_$senderId.json"; // Ruta al archivo donde se guardan los datos
        if (file_exists($archivoDatos)) {
            $contenido = file_get_contents($archivoDatos); // Lee el contenido del archivo
            $datos = json_decode($contenido, true); // Decodifica el JSON a un arreglo asociativo
            return $datos; // Devuelve el arreglo con los datos
        }
        return ['estado' => null, 'ticketId' => null]; // Devuelve valores predeterminados si el archivo no existe
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    // Aquí empieza la lógica de procesamiento de la solicitud entrante

    try {
        $pdo_options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $pdo = new PDO('dblib:host=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'glpi', 'xxxxxxxxxxxxxxxxxxxxxx', $pdo_options);
    } catch (Exception $e) {
        die('Erreur : ' . $e->getMessage());
    }

    if (isset($_GET['hub_challenge'])) {
        echo $_GET['hub_challenge'];
        exit;
    }

    $getContent = file_get_contents('php://input');
    $log->info("Received content: $getContent");

    $input = json_decode($getContent, true);

    if (isset($input['entry'][0]['changes'][0]['value']['messages'][0])) {
        $messageData = $input['entry'][0]['changes'][0]['value']['messages'][0];
        $senderId = $input['entry'][0]['changes'][0]['value']['messages'][0]['from'];

                    // Verificar si el mensaje contiene una imagen
                    if (isset($messageData['type']) && $messageData['type'] === 'image') {
                        $mediaId = $messageData['image']['id'];
                        $imageUrl = getImageUrlFromMediaId($mediaId);
                    
                        if ($imageUrl !== null) {
                            $log->info("URL of the received image: " . $imageUrl);
                            $savePath = './media/image_' . time(); // Asegúrate de que este directorio exista y tenga permisos adecuados
                            $downloadedImagePath = downloadMedia($imageUrl, $savePath);
                    
                            if ($downloadedImagePath !== false) {
                                $log->info("Image downloaded successfully: " . $downloadedImagePath);
                                
                                $filePathDescargado = $downloadedImagePath;

                                // Asegúrate de tener el ticketIdExistente que corresponde al ticket actual del usuario
                                // $ticketIdExistente = /* El ID del ticket existente */;

                                // Asumiendo que tienes una función para obtener el session_token
                                $sessionToken = getGlpiSessionToken($api_url, $app_token, $user_token);
                                $log->info("
                                File path downloaded: " . $filePathDescargado);

                                if ($sessionToken && $filePathDescargado) {
                                    // Subir el archivo al servidor de GLPI y obtener el ID del documento
                                    $documentId = uploadFileToGlpi($api_url, $sessionToken, $app_token, $filePathDescargado);
                                    $log->info("DocumentId: " . $documentId);


                                    if ($documentId) {
                                        // Asociar el documento subido al ticket existente en GLPI
                                        associateFileToTicket($api_url, $sessionToken, $app_token, $ticketId, $message, $documentId, $mediaId);
                                        $log->info("File associated with the ticket successfully.");
                                    } else {
                                        $log->info("Error uploading file to GLPI.");
                                    }
                                } else {
                                    $log->info("Error getting GLPI session token or downloading image.");
                                }
                                
                            } else {
                                $log->info("Error downloading the image.");
                            }
                        } else {
                            $log->info("Could not get the image URL.");
                        }
                    }
        $log->info("Sender's phone number: $senderId");

        $datosUsuario = obtenerDatos($senderId);
        $estadoActual = $datosUsuario['estado'] ?? null;
        $ticketIdExistente = $datosUsuario['ticketId'] ?? null;
        
        $senderId = $messageData['from'];
        $messageText = $messageData['text']['body'] ?? null; // Este es el texto para mensajes normales
        $buttonPayload = null; // Inicializamos como null para los botones

        if (strtolower($messageText) === 'exit') {
            limpiarEstado($senderId);
            sendMessage($senderId, "Operation canceled. If you need assistance later, feel free to message us again.");
            $log->info("Operation canceled by user: $senderId");
            exit; // Finaliza la ejecución del script
        }

        $sql_check = "SELECT COUNT(*) AS count2 FROM dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL WHERE wamid = '$senderId'";
            $result2 = $pdo->query($sql_check);
            $data2 = $result2->fetchAll();

            $result_check = $data2[0]['count2'];

            $log->info("Check if number phone exists in DB completed $result_check");
            $log->info("Actual State is: $estadoActual");
            

        if ($result_check != 0) {
            // El número de teléfono del remitente está en la base de datos
            // Obtener el número de teléfono asociado al ticket
            $sql_check_ticket = "SELECT COUNT(*) AS count FROM dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL WHERE wamid = :senderId";
            $stmt_check_ticket = $pdo->prepare($sql_check_ticket);
            $stmt_check_ticket->bindParam(':senderId', $senderId);
            $stmt_check_ticket->execute();
            $result_check_ticket = $stmt_check_ticket->fetch(PDO::FETCH_ASSOC);
        
            if ($result_check_ticket['count'] > 0) {
                // El número de teléfono del remitente coincide con el número asociado al ticket
                $session_token = getGlpiSessionToken($api_url, $app_token, $user_token);
                if ($session_token) {
                    if (updateGlpiTicket($api_url, $session_token, $app_token, $ticketIdExistente, $messageText)) {
                        sendMessage($senderId, "Your message has been added to the existing ticket #$ticketIdExistente.");
                    } else {
                        sendMessage($senderId, "There was an error adding your message to the ticket. Please try again.");
                    }
                } else {
                    sendMessage($senderId, "Error obtaining GLPI session token.");
                }
            } else {
                // El número de teléfono del remitente no coincide con el número asociado al ticket
                sendMessage($senderId, "The existing ticket is not associated with your phone number.");
            }
        
        }else if ($result_check == 0 || $estadoActual === 'esperando_confirmacion') {
            // Verificamos si hay un botón interactivo o una respuesta de botón
            if (isset($messageData['interactive'])) { // Cambiar según la estructura real del webhook
                $buttonPayload = $messageData['interactive']['button_reply']['id']; // Asegúrate de que esta ruta refleje la estructura real del webhook
                }
        
            // A continuación, tu lógica para manejar $messageText y $buttonPayload...
            if (isNumberAllowed($senderId)) {
                $estadoActual = obtenerEstado($senderId);

                    if ($estadoActual === false) {
                        // No hay estado, se asume que es el inicio de la conversación
                        guardarEstado($senderId, 'esperando_confirmacion');
                        $buttons = [
                            [
                                "title" => "Yes",
                                "payload" => "YES_PAYLOAD"
                            ],
                            [
                                "title" => "No",
                                "payload" => "NO_PAYLOAD"
                            ]
                        ];
                        sendMessage($senderId, "*Welcome to xxxxxxxxx critical channel* \n\nDo you want to create a new critical request?", $buttons);
                    }elseif ($estadoActual == 'esperando_confirmacion') {
                    
                        if ($buttonPayload) {
                            if ($buttonPayload === 'YES_PAYLOAD') {
                                guardarEstado($senderId, 'esperando_contenido');
                                sendMessage($senderId, "Thank you!\n\nNow please explain what the problem is and the current situation: ");
                            } elseif ($buttonPayload === 'NO_PAYLOAD') {
                                sendMessage($senderId, "Ticket creation canceled.");
                                limpiarEstado($senderId);
                            }
                        } else {
                            sendMessage($senderId, "Please select a valid option (Yes or No).");
                        }
                    
                    } elseif ($estadoActual == 'esperando_contenido') {

                        $senderId = '+' . $senderId;

                        $requesterId= $arrayAllowedNumbers[$senderId][0];
                        $contenidoTicket = $messageText;
                        $nombreTicket = "Critical request from ".$arrayAllowedNumbers[$senderId][1]." (".$senderId.")";

                        $log->info("Ticket Name: $nombreTicket");
                        $log->info("Ticket Content: $contenidoTicket");
                        $log->info("Sender id: $senderId");
                        $log->info("Requester id: $requesterId");

                        $session_token = getGlpiSessionToken($api_url, $app_token, $user_token);
                        if ($session_token) {
                            $ticket_details = [
                                'name' => $nombreTicket,
                                'content' => $contenidoTicket,
                                'type' => 1,
                                'status' => 2,
                                'urgency' => 5,
                                'impact' => 5,
                                'priority' => 6,
                                'itilcategories_id' => 1,
                                '_users_id_requester' => $requesterId,
                                '_users_id_assign' => 652,
                            ];
                            $ticketId = createGlpiTicket($api_url, $session_token, $app_token, $ticket_details); // Asumiendo que ahora devuelve ID
                            if ($ticketId) {
                                // Almacena tanto el estado como el ID del ticket para el usuario
                                guardarDatos($senderId, 'esperando_contenido', $ticketId);
                                sendMessage($senderId, "We have received your request and we are working on it, soon a technician will contact you. \n\n Your ticket ID is: $ticketId \n\n Thank you for contacting us.");
                    
                                // Aquí comienza el código de envío de mensaje a Discord
                                $webhookUrl = 'https://discord.com/api/webhooks/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
                                $message = "<@240241749140832256> A new critical ticket has been created: [Ticket Nº$ticketId](https://helpdesk.xxxxxxxxx.com/front/ticket.form.php?id=$ticketId)"; // Asegúrate de incluir el ID del ticket

                                $data = ['content' => $message];
                                $options = [
                                    'http' => [
                                        'header' => "Content-Type: application/json\r\n",
                                        'method' => 'POST',
                                        'content' => json_encode($data),
                                    ],
                                ];
                                $context = stream_context_create($options);
                                $response = file_get_contents($webhookUrl, false, $context);
                                // Aquí termina el código de envío de mensaje a Discord

                                $senderId = removePlusIfNeeded($senderId);

                                $sql = "INSERT INTO dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL (wamid) VALUES (:wamid)";
                                $stmt = $pdo->prepare($sql);
                                $stmt->bindParam(':wamid', $senderId);
                                $stmt->execute();

                                $sql_associate = "UPDATE dbo.A_TBL_WHATSAPP_ALTASOFT_CRITICAL SET ticket_id = :ticketId WHERE wamid = :senderId";
                                $stmt_associate = $pdo->prepare($sql_associate);
                                $stmt_associate->bindParam(':ticketId', $ticketId);
                                $stmt_associate->bindParam(':senderId', $senderId);
                                $stmt_associate->execute();
                        
                                if (!$stmt) {
                                    echo "Error executing SQL query.";
                                }
                        
                                $log->info("The wamid has been inserted into the database");
                    

                            } else {
                                sendMessage($senderId, "Error opening ticket in GLPI.");
                            }            
                        } else {
                            sendMessage($senderId, "Error getting GLPI session token.");
                        }
                        limpiarEstado($senderId);
                    
                    } 
            }else {
                sendMessage($senderId, "You are not allowed to open a critical ticket.");
            }
        }
    }
$log->info("================================ END ================================");
?>