<?php
// phpinfo();exit;
require_once("functions.php");
session_start();

header('Content-Type: text/html; charset=utf-8');

global $CLIENT_ID, $CLIENT_SECRET, $REDIRECT_URI;
/*
echo "<hr>CODE:".$_GET["code"];
echo "<hr>CLIENT_ID:".$CLIENT_ID;
echo "<hr>CLIENT_SECRET:".$CLIENT_SECRET;
echo "<hr>REDIRECT_URI:".$REDIRECT_URI;
*/


$client = new Google_Client();
$client->setClientId($CLIENT_ID);
$client->setClientSecret($CLIENT_SECRET);
$client->setRedirectUri($REDIRECT_URI);
$client->setScopes('email');


$authUrl = $client->createAuthUrl();
// echo "<hr>authUrl:".$authUrl;
// print_r($authUrl);
// echo "<hr>";

$credentials = getCredentials($_GET['code'], $authUrl);

// echo "<hr>CREDENTIALS:".$credentials;

$client->setAccessToken($credentials);
$service = new Google_Service_Drive($client);

// $idsToDelete = getFilesInFolderPreviousUpdate($service, $folderName); // Para borrar en el Drive todo lo que haya en esa carpeta de antes 

$files = $service->files->listFiles();
foreach ($files['items'] as $item) { ?>
 <a target="_blank" href="<?=$item['alternateLink'];?>"> <?=$item['title'];?> </a>
 <br>
 <?php
}

exit;

$idsAll = getAllFiles($service); 
echo "<hr>idsAll:";
print_r($idsAll);
exit;
// echo "<hr>folderOrigin:".$folderOrigin;

$escanearFicheros = scandir($folderOrigin);
$arrFicheros = array();

foreach ($escanearFicheros as $fichero) {

    if (($fichero != ".") && ($fichero != "..")) {
        // array_push($arrFicheros, [$fichero, getTipoFichero($fichero)]);
        $arrAux = array();
        $arrAux[0] = $fichero;
        $arrAux[1] = mime_content_type($folderOrigin.$fichero);
        $arrFicheros[] = $arrAux;
    	// echo "<hr>".$fichero;
    	// echo "<hr>".getTipoFichero($fichero);
    }
}


// echo "<hr>arrFicheros:";
// print_r($arrFicheros);


foreach ($arrFicheros as $valor) {
    insertFile($service, $valor[0], "Descripción de ".$valor[0], $valor[1], $folderOrigin.$valor[0], $folderName, $folderDesc);
    echo "<hr>Subiendo al Drive a la carpeta ".$folderName." el fichero: ".$valor[0]." con tipo ".$valor[1];
	// print_r($valor);
}
deleteItems($idsToDelete, $service); 




// ***************************************************
// ***************************************************
// FUNCIONES
// ***************************************************
// ***************************************************



/**
* Get the folder ID if it exists, if it doesnt exist, create it and return the ID
*
* @param Google_DriveService $service Drive API service instance.
* @param String $folderName Name of the folder you want to search or create
* @param String $folderDesc Description metadata for Drive about the folder (optional)
* @return Google_Drivefile that was created or got. Returns NULL if an API error occured
*/
function getFolderExistsCreate($service, $folderName, $folderDesc) {
	// List all user files (and folders) at Drive root
	$files = $service->files->listFiles();
	$found = false;

	// Go through each one to see if there is already a folder with the specified name
	foreach ($files['items'] as $item) {
		if ($item['title'] == $folderName) {
			$found = true;
			return $item['id'];
			break;
		}
	}

	// If not, create one
	if ($found == false) {
		$folder = new Google_Service_Drive_DriveFile();

		//Setup the folder to create
		$folder->setTitle($folderName);

		if(!empty($folderDesc))
			$folder->setDescription($folderDesc);

		$folder->setMimeType('application/vnd.google-apps.folder');

		//Create the Folder
		try {
			$createdFile = $service->files->insert($folder, array(
				'mimeType' => 'application/vnd.google-apps.folder',
				));

			// Return the created folder's id
			return $createdFile->id;
		} catch (Exception $e) {
			print "An error occurred: " . $e->getMessage();
		}
	}
}

/**
 * Insert new file in the Application Data folder.
 *
 * @param Google_DriveService $service Drive API service instance.
 * @param string $title Title of the file to insert, including the extension.
 * @param string $description Description of the file to insert.
 * @param string $mimeType MIME type of the file to insert.
 * @param string $filename Filename of the file to insert.
 * @return Google_DriveFile The file that was inserted. NULL is returned if an API error occurred.
 */
function insertFile($service, $title, $description, $mimeType, $filename, $folderName, $folderDesc) {
	$file = new Google_Service_Drive_DriveFile();

	// Set the metadata
	$file->setTitle($title);
	$file->setDescription($description);
	$file->setMimeType($mimeType);

	// Setup the folder you want the file in, if it is wanted in a folder
	if(isset($folderName)) {
		if(!empty($folderName)) {
			$parent = new Google_Service_Drive_ParentReference();
			$parent->setId(getFolderExistsCreate($service, $folderName, $folderDesc));
			$file->setParents(array($parent));
		}
	}
	try {
		// Get the contents of the file uploaded
		$data = file_get_contents($filename);

		// Try to upload the file, you can add the parameters e.g. if you want to convert a .doc to editable google format, add 'convert' = 'true'
		$createdFile = $service->files->insert($file, array(
			'data' => $data,
			'mimeType' => $mimeType,
			'uploadType'=> 'multipart'
			));

		// Return a bunch of data including the link to the file we just uploaded
		return $createdFile;
	} catch (Exception $e) {
		print "An error occurred: " . $e->getMessage();
	}
}

function emptyFolder($service, $folderName)
{
    $files = $service->files->listFiles();
    foreach ($files['items'] as $item)
    {
        if ($item['title'] == $folderName)
        {

            $idFolder = $item['id'];

        }
    }

    if(isset($idFolder))
    {
        foreach ($files['items'] as $item)
        {
            $service->parents->delete($item['id'], $idFolder);
        }
    }
}


function getAllFiles($service) {
	$files = $service->files->listFiles();
    $idsInFolder = array();
    $idFolder = null;
    

    foreach ($files['items'] as $item)  {
    	print_r($item);
    	echo "<hr>";
        array_push($idsInFolder, $item['id']);
    }

    return $idsInFolder;

}

/*
Esta función va a guardar los ID de los archivos en un array para borrarlos tras subir los nuevos.
Lo hacemos en este orden por si falla la subida por timeout (que a veces pasa) no dejar el directorio vacio una hora.
*/
function getFilesInFolderPreviousUpdate($service, $folderName)
{
    $files = $service->files->listFiles();
    $idsInFolder = array();
    $idFolder = null;
    foreach ($files['items'] as $item)
    {
        if ($item['title'] == $folderName)
        {

            $idFolder = $item['id'];

        }
    }
    array_push($idsInFolder, $idFolder);

    foreach ($files['items'] as $item)
    {
        if ($item['id'] != $idFolder)
        {

            if(isFileInFolder($service, $idFolder, $item['id']))
            {
                array_push($idsInFolder, $item['id']);
                //echo $item['id']."<br>";
            }

        }
    }

    return $idsInFolder;
}

function deleteItems($idItemToDelete, $service)
{
    if(count($idItemToDelete)>1)
    {
        $idFolder = $idItemToDelete[0];
        for($i=1;$i<count($idItemToDelete);$i++)
        {
            $service->parents->delete($idItemToDelete[$i], $idFolder);
        }
    }
}

function isFileInFolder($service, $folderId, $fileId) {
    try {
        $service->parents->get($fileId, $folderId);
    } catch (apiServiceException $e) {
        if ($e->getCode() == 404) {
            return false;
        } else {
            //print "An error occurred: " . $e->getMessage();
            //throw $e;
            return false;
        }
    } catch (Exception $e) {
        //print "An error occurred: " . $e->getMessage();
        //throw $e;
        return false;
    }
    return true;
}

function printFilesInFolder($service, $folderId) {
    $pageToken = NULL;

    do {
        try {
            $parameters = array();
            if ($pageToken) {
                $parameters['pageToken'] = $pageToken;
            }
            $children = $service->children->listChildren($folderId, $parameters);

            foreach ($children->getItems() as $child) {
                print 'File Id: ' . $child->getId();
            }
            $pageToken = $children->getNextPageToken();
        } catch (Exception $e) {
            print "An error occurred: " . $e->getMessage();
            $pageToken = NULL;
        }
    } while ($pageToken);
}


