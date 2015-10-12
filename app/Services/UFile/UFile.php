<?php namespace App\Services\UFile;
use App\Services\UFile\Library\Conf as Conf;
use App\Services\UFile\Library\ActionType as ActionType;
use App\Services\UFile\Library\Utils as Utils;
use App\Services\UFile\Library\HTTP_Request as HTTP_Request;
use App\Services\UFile\Library\HTTP_Utils as HTTP_Utils;
use App\Services\UFile\Library\UCloud_AuthHttpClient as UCloud_AuthHttpClient;
use App\Services\UFile\Library\Digest as Digest;


class UFile 
{
 
	function UCloud_PutFile($bucket, $key, $file)
	{
		$action_type = ActionType::PUTFILE;
	    $err = Utils::CheckConfig(ActionType::PUTFILE);
	    if ($err != null) {
	        return array(null, $err);
	    }

		$f = @fopen($file, "r");
		if (!$f) return array(null, new UCloud_Error(-1, -1, "open $file error"));

    	$host = $bucket . Conf::UCLOUD_PROXY_SUFFIX;
	    $path = $key;
	    $content  = @fread($f, filesize($file));
	    list($mimetype, $err) = Utils::GetFileMimeType($file);
	    if ($err) {
	        fclose($f);
	        return array("", $err);
	    }

	    $req = new HTTP_Request('PUT', array('host'=>$host, 'path'=>$path), $content, $bucket, $key, $action_type);
	    $req->Header['Expect'] = '';
	    $req->Header['Content-Type'] = $mimetype;

	    $client = new UCloud_AuthHttpClient(null, $mimetype);
	    list($data, $err) = HTTP_Utils::UCloud_Client_Call($client, $req);
	    fclose($f);
	    return array($data, $err);
	}

	function UCloud_MultipartForm($bucket, $key, $file)
	{
		$action_type = ActionType::POSTFILE;
	    $err = Utils::CheckConfig(ActionType::POSTFILE);
	    if ($err != null) {
	        return array(null, $err);
	    }

		$f = @fopen($file, "r");
		if (!$f) return array(null, new UCloud_Error(-1, -1, "open $file error"));

	    $host = $bucket . Conf::UCLOUD_PROXY_SUFFIX;
	    $path = "";
	    $content = @fread($f, filesize($file));
	    if ($content == FALSE) {
	        if (feof($f)) break;
	        fclose($f);
	        return array(null, new UCloud_Error(0, -1, "read file error"));
	    }
	    list($mimetype, $err) = Utils::GetFileMimeType($file);
	    if ($err) {
	        fclose($f);
	        return array("", $err);
	    }

	    $req = new HTTP_Request('POST', array('host'=>$host, 'path'=>$path), $content, $bucket, $key, $action_type);
	    $req->Header['Expect'] = '';
	    $token = Digest::UCloud_SignRequest(null, $req, $mimetype);

	    $fields = array('Authorization'=>$token, 'FileName' => $key);
	    $files  = array('files'=>array('file', $file, $content, $mimetype));

	    $client = new UCloud_AuthHttpClient(null, NO_AUTH_CHECK);
	    list($data, $err) = HTTP_Utils::UCloud_Client_CallWithMultipartForm($client, $req, $fields, $files);
	    fclose($f);
	    return array($data, $err);
	}



	//------------------------------分片上传------------------------------
	function UCloud_MInit($bucket, $key)
	{
	    $err = Utils::CheckConfig(ActionType::MINIT);
	    if ($err != null) {
	        return array(null, $err);
	    }

		$host = $bucket . Conf::UCLOUD_PROXY_SUFFIX;
	    $path = $key."?uploads";

	    $req = new HTTP_Request('POST', array('host'=>$host, 'path'=>$path), null, $bucket, $key);
	    $req->Header['Content-Type'] = 'application/x-www-form-urlencoded';

	    $client = new UCloud_AuthHttpClient(null);
	    return  HTTP_Utils::UCloud_Client_Call($client, $req);
	}

	//@results: (tagList, err)
	function UCloud_MUpload($bucket, $key, $file, $uploadId, $blkSize, $partNumber=0)
	{
	    $err = Utils::CheckConfig(ActionType::MUPLOAD);
	    if ($err != null) {
	        return array(null, $err);
	    }

		$f = @fopen($file, "r");
		if (!$f) return array(null, new UCloud_Error(-1, -1, "open $file error"));

	    $etagList = array();
	    list($mimetype, $err) = Utils::GetFileMimeType($file);
	    if ($err) {
	        fclose($f);
	        return array("", $err);
	    }
	    $client   = new UCloud_AuthHttpClient(null);
	    for(;;) {
	        
			$host = $bucket . Conf::UCLOUD_PROXY_SUFFIX;
	        $path = $key . "?uploadId=$uploadId&partNumber=$partNumber";
	        if (@fseek($f, $blkSize*$partNumber, SEEK_SET) < 0) {
	            fclose($f);
	            return array(null, new UCloud_Error(0, -1, "fseek error"));
	        }
	        $content = @fread($f, $blkSize);
	        if ($content == FALSE) {
	            if (feof($f)) break;
	            fclose($f);
	            return array(null, new UCloud_Error(0, -1, "read file error"));
	        }
	        $req = new HTTP_Request('PUT', array('host'=>$host, 'path'=>$path), $content, $bucket, $key);
	        $req->Header['Content-Type'] = $mimetype;
	        $req->Header['Expect'] = '';
	        list($data, $err) = HTTP_Utils::UCloud_Client_Call($client, $req);
	        if ($err) {
	            fclose($f);
	            return array(null, $err);
	        }
	        $etag = @$data['ETag'];
	        $part = @$data['PartNumber'];
	        if ($part != $partNumber) {
	            fclose($f);
	            return array(null, new UCloud_Error(0, -1, "unmatch partnumber"));
	        }
	        $etagList[] = $etag;
	        $partNumber += 1;
	    }
	    fclose($f);
	    return array($etagList, null);
	}

	function UCloud_MFinish($bucket, $key, $uploadId, $etagList, $newKey = '')
	{
	    $err = Utils::CheckConfig(ActionType::MFINISH);
	    if ($err != null) {
	        return array(null, $err);
	    }

		$host = $bucket . Conf::UCLOUD_PROXY_SUFFIX;
		$path = $key . "?uploadId=$uploadId";
		if ($newKey != '') $path .= "&newKey=$newKey";

	    $body = @implode(',', $etagList);
	    $req = new HTTP_Request('POST', array('host'=>$host, 'path'=>$path), $body, $bucket, $key);
	    $req->Header['Content-Type'] = 'text/plain';

	    $client = new UCloud_AuthHttpClient(null);
	    return HTTP_Utils::UCloud_Client_Call($client, $req);
	}

	function UCloud_MCancel($bucket, $key, $uploadId)
	{
	    $err = Utils::CheckConfig(ActionType::MCANCEL);
	    if ($err != null) {
	        return array(null, $err);
	    }

		$host = $bucket . Conf::UCLOUD_PROXY_SUFFIX;
		$path = $key . "?uploadId=$uploadId";

	    $req = new HTTP_Request('DELETE', array('host'=>$host, 'path'=>$path), null, $bucket, $key);
	    $req->Header['Content-Type'] = 'application/x-www-form-urlencoded';

	    $client = new UCloud_AuthHttpClient(null);
	    return HTTP_Utils::UCloud_Client_Call($client, $req);
	}

	function UCloud_UploadHit($bucket, $key, $file)
	{
	    $err = Utils::CheckConfig(ActionType::UPLOADHIT);
	    if ($err != null) {
	        return array(null, $err);
	    }

		$f = @fopen($file, "r");
		if (!$f) return array(null, new UCloud_Error(-1, -1, "open $file error"));

	    $fileSize = filesize($file);
	    $content  = @fread($f, $fileSize);
	    if ($content == FALSE) {
	        if (feof($f)) break;
	        fclose($f);
	        return array(null, new UCloud_Error(0, -1, "read file error"));
	    }
	    list($fileHash, $err) = Utils::UCloud_FileHash($file);
	    if ($err) {
	        fclose($f);
	        return array(null, $err);
	    }
	    fclose($f);

	    $host = $bucket . Conf::UCLOUD_PROXY_SUFFIX;
	    $path = "uploadhit?Hash=$fileHash&FileName=$key&FileSize=$fileSize";

	    $req = new HTTP_Request('POST', array('host'=>$host, 'path'=>$path), null, $bucket, $key);
	    $req->Header['Content-Type'] = 'application/x-www-form-urlencoded';

	    $client = new UCloud_AuthHttpClient(null);
	    return HTTP_Utils::UCloud_Client_Call($client, $req);
	}

	//------------------------------删除文件------------------------------
	function UCloud_Delete($bucket, $key)
	{

	    $err = Utils::CheckConfig(ActionType::DELETE);
	    if ($err != null) {
	        return array(null, $err);
	    }

	    $host = $bucket . Conf::UCLOUD_PROXY_SUFFIX;
	    $path = "$key";

	    $req = new HTTP_Request('DELETE', array('host'=>$host, 'path'=>$path), null, $bucket, $key);
	    $req->Header['Content-Type'] = 'application/x-www-form-urlencoded';

	    $client = new UCloud_AuthHttpClient(null);
	    return HTTP_Utils::UCloud_Client_Call($client, $req);
	}

	//------------------------------生成公有文件Url------------------------------
	// @results: $url
	function UCloud_MakePublicUrl($bucket, $key)
	{

	    $url = $bucket . Conf::UCLOUD_PROXY_SUFFIX . "/" . rawurlencode($key);
	    return $url;
	}
	//------------------------------生成私有文件Url------------------------------
	// @results: $url
	function UCloud_MakePrivateUrl($bucket, $key, $expires = 0)
	{

	    $err = Utils::CheckConfig(ActionType::GETFILE);
	    if ($err != null) {
	        return array(null, $err);
	    }

	    global $UCLOUD_PUBLIC_KEY;

	    $public_url = $this->UCloud_MakePublicUrl($bucket, $key);
	    $req = new HTTP_Request('GET', array('path'=>$public_url), null, $bucket, $key);
	    if ($expires > 0) {
	        $req->Header['Expires'] = $expires;
	    }

	    $client = new UCloud_AuthHttpClient(null);
	    $temp = $client->Auth->SignRequest($req, null, QUERY_STRING_CHECK);
	    $signature = substr($temp, -28, 28);
	    $url = $public_url . "?UCloudPublicKey=" . Conf::UCLOUD_PUBLIC_KEY . "&Signature=" . $signature;
	    if ('' != $expires) {
	        $url .= "&Expires=" . $expires;
	    }
	    return $url;
	}



}
