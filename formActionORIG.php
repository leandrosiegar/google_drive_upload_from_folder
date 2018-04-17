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

$idsToDelete = getFilesInFolderPreviousUpdate($service, $folderName); // Para borrar en el Drive todo lo que haya en esa carpeta de antes 
// echo "<hr>idsToDelete:";
// print_r($idsToDelete);
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


function xxxgetTipoFichero($fichero) {
	$idx = explode( '.', $fichero);
	$count_explode = count($idx);
	$idx = strtolower($idx[$count_explode-1]);
 
	$mimet = array(	
        'ai' =>'application/postscript',
	'aif' =>'audio/x-aiff',
	'aifc' =>'audio/x-aiff',
	'aiff' =>'audio/x-aiff',
	'asc' =>'text/plain',
	'atom' =>'application/atom+xml',
	'avi' =>'video/x-msvideo',
	'bcpio' =>'application/x-bcpio',
	'bmp' =>'image/bmp',
	'cdf' =>'application/x-netcdf',
	'cgm' =>'image/cgm',
	'cpio' =>'application/x-cpio',
	'cpt' =>'application/mac-compactpro',
	'crl' =>'application/x-pkcs7-crl',
	'crt' =>'application/x-x509-ca-cert',
	'csh' =>'application/x-csh',
	'css' =>'text/css',
	'dcr' =>'application/x-director',
	'dir' =>'application/x-director',
	'djv' =>'image/vnd.djvu',
	'djvu' =>'image/vnd.djvu',
	'doc' =>'application/msword',
	'dtd' =>'application/xml-dtd',
	'dvi' =>'application/x-dvi',
	'dxr' =>'application/x-director',
	'eps' =>'application/postscript',
	'etx' =>'text/x-setext',
	'ez' =>'application/andrew-inset',
	'gif' =>'image/gif',
	'gram' =>'application/srgs',
	'grxml' =>'application/srgs+xml',
	'gtar' =>'application/x-gtar',
	'hdf' =>'application/x-hdf',
	'hqx' =>'application/mac-binhex40',
	'html' =>'text/html',
	'html' =>'text/html',
	'ice' =>'x-conference/x-cooltalk',
	'ico' =>'image/x-icon',
	'ics' =>'text/calendar',
	'ief' =>'image/ief',
	'ifb' =>'text/calendar',
	'iges' =>'model/iges',
	'igs' =>'model/iges',
	'jpe' =>'image/jpeg',
	'jpeg' =>'image/jpeg',
	'jpg' =>'image/jpeg',
	'js' =>'application/x-javascript',
	'kar' =>'audio/midi',
	'latex' =>'application/x-latex',
	'm3u' =>'audio/x-mpegurl',
	'man' =>'application/x-troff-man',
	'mathml' =>'application/mathml+xml',
	'me' =>'application/x-troff-me',
	'mesh' =>'model/mesh',
	'mid' =>'audio/midi',
	'midi' =>'audio/midi',
	'mif' =>'application/vnd.mif',
	'mov' =>'video/quicktime',
	'movie' =>'video/x-sgi-movie',
	'mp2' =>'audio/mpeg',
	'mp3' =>'audio/mpeg',
	'mpe' =>'video/mpeg',
	'mpeg' =>'video/mpeg',
	'mpg' =>'video/mpeg',
	'mpga' =>'audio/mpeg',
	'ms' =>'application/x-troff-ms',
	'msh' =>'model/mesh',
	'mxu m4u' =>'video/vnd.mpegurl',
	'nc' =>'application/x-netcdf',
	'oda' =>'application/oda',
	'ogg' =>'application/ogg',
	'pbm' =>'image/x-portable-bitmap',
	'pdb' =>'chemical/x-pdb',
	'pdf' =>'application/pdf',
	'pgm' =>'image/x-portable-graymap',
	'pgn' =>'application/x-chess-pgn',
	'php' =>'application/x-httpd-php',
	'php4' =>'application/x-httpd-php',
	'php3' =>'application/x-httpd-php',
	'phtml' =>'application/x-httpd-php',
	'phps' =>'application/x-httpd-php-source',
	'png' =>'image/png',
	'pnm' =>'image/x-portable-anymap',
	'ppm' =>'image/x-portable-pixmap',
	'ppt' =>'application/vnd.ms-powerpoint',
	'ps' =>'application/postscript',
	'qt' =>'video/quicktime',
	'ra' =>'audio/x-pn-realaudio',
	'ram' =>'audio/x-pn-realaudio',
	'ras' =>'image/x-cmu-raster',
	'rdf' =>'application/rdf+xml',
	'rgb' =>'image/x-rgb',
	'rm' =>'application/vnd.rn-realmedia',
	'roff' =>'application/x-troff',
	'rtf' =>'text/rtf',
	'rtx' =>'text/richtext',
	'sgm' =>'text/sgml',
	'sgml' =>'text/sgml',
	'sh' =>'application/x-sh',
	'shar' =>'application/x-shar',
	'shtml' =>'text/html',
	'silo' =>'model/mesh',
	'sit' =>'application/x-stuffit',
	'skd' =>'application/x-koan',
	'skm' =>'application/x-koan',
	'skp' =>'application/x-koan',
	'skt' =>'application/x-koan',
	'smi' =>'application/smil',
	'smil' =>'application/smil',
	'snd' =>'audio/basic',
	'spl' =>'application/x-futuresplash',
	'src' =>'application/x-wais-source',
	'sv4cpio' =>'application/x-sv4cpio',
	'sv4crc' =>'application/x-sv4crc',
	'svg' =>'image/svg+xml',
	'swf' =>'application/x-shockwave-flash',
	't' =>'application/x-troff',
	'tar' =>'application/x-tar',
	'tcl' =>'application/x-tcl',
	'tex' =>'application/x-tex',
	'texi' =>'application/x-texinfo',
	'texinfo' =>'application/x-texinfo',
	'tgz' =>'application/x-tar',
	'tif' =>'image/tiff',
	'tiff' =>'image/tiff',
	'tr' =>'application/x-troff',
	'tsv' =>'text/tab-separated-values',
	'txt' =>'text/plain',
	'ustar' =>'application/x-ustar',
	'vcd' =>'application/x-cdlink',
	'vrml' =>'model/vrml',
	'vxml' =>'application/voicexml+xml',
	'wav' =>'audio/x-wav',
	'wbmp' =>'image/vnd.wap.wbmp',
	'wbxml' =>'application/vnd.wap.wbxml',
	'wml' =>'text/vnd.wap.wml',
	'wmlc' =>'application/vnd.wap.wmlc',
	'wmlc' =>'application/vnd.wap.wmlc',
	'wmls' =>'text/vnd.wap.wmlscript',
	'wmlsc' =>'application/vnd.wap.wmlscriptc',
	'wmlsc' =>'application/vnd.wap.wmlscriptc',
	'wrl' =>'model/vrml',
	'xbm' =>'image/x-xbitmap',
	'xht' =>'application/xhtml+xml',
	'xhtml' =>'application/xhtml+xml',
	'xls' =>'application/vnd.ms-excel',
	'xlsx' =>'application/vnd.ms-excel',
	'xml xsl' =>'application/xml',
	'xpm' =>'image/x-xpixmap',
	'xslt' =>'application/xslt+xml',
	'xul' =>'application/vnd.mozilla.xul+xml',
	'xwd' =>'image/x-xwindowdump',
	'xyz' =>'chemical/x-xyz',
	'zip' =>'application/zip'
	);
 
	if (isset( $mimet[$idx] )) {
	 return $mimet[$idx];
	} else {
	 return 'application/octet-stream';
	}
}


