<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors','0');
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','HEAD'],true)) throw new AzHttpError(405,'Use GET or HEAD to view a video.','method_not_allowed');
    if (!az_is_installed()) throw new AzHttpError(404,'Video not found.','not_found');
    $id=az_text($_GET['id']??'',32,'Video ID');
    $type=az_text($_GET['type']??'video',10,'Media type');
    if (!in_array($type,['video','cover'],true)) throw new AzHttpError(404,'Media not found.','not_found');
    $row=az_model_video_any_row($id);
    $viewer=az_current_user();
    $isOwner=$viewer && (int)$viewer['id']===(int)$row['user_id'];
    $isAdmin=$viewer && az_has_role($viewer,'admin');
    if (!$isOwner && !$isAdmin) {
        // Independent public access verifies approval, consent, active owner and model role.
        $row=az_model_video_public_row($id);
    }
    if ($type==='cover') {
        if (!$row['cover_storage_name']) throw new AzHttpError(404,'Cover not available.','not_found');
        $storage=['storage_name'=>$row['cover_storage_name']];$mime=$row['cover_mime_type'];
        $filename='model-cover.'.match((string)$mime){'image/png'=>'png','image/webp'=>'webp',default=>'jpg'};
    } else {
        $storage=$row;$mime=$row['mime_type'];$filename=$row['title'].'.'.match((string)$mime){'video/mp4'=>'mp4','video/quicktime'=>'mov','video/ogg'=>'ogv',default=>'webm'};
        if (!str_starts_with((string)$mime,'video/')) throw new AzHttpError(404,'Video format invalid.','not_found');
    }
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $handle=@fopen(az_media_path($storage),'rb');
    if (!$handle) throw new AzHttpError(404,'Video is unavailable.','media_missing');
    $size=(int)((fstat($handle)['size']??0));
    if ($size<1) {fclose($handle);throw new AzHttpError(404,'Video is unavailable.','media_missing');}
    while(ob_get_level()>0) ob_end_clean();
    @ini_set('zlib.output_compression','0');
    header('Content-Type: '.$mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    $safe=trim(preg_replace('/[^A-Za-z0-9._ -]/','',(string)$filename)??'', '. ');
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.substr($safe?:'azhottie-video',0,140).'"');
    $start=0;$end=$size-1;
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range=trim((string)$_SERVER['HTTP_RANGE']);
        if ($type==='cover' || preg_match('/\Abytes=([0-9]*)-([0-9]*)\z/D',$range,$matches)!==1 || ($matches[1]===''&&$matches[2]==='')) {
            fclose($handle);header('Content-Range: bytes */'.$size);http_response_code(416);exit;
        }
        if ($matches[1]==='') {$length=min($size,(int)$matches[2]);$start=$size-$length;}
        else {$start=(int)$matches[1];if ($matches[2]!=='')$end=min($end,(int)$matches[2]);}
        if ($start>=$size || $start<0 || $end<$start) {fclose($handle);header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    $left=$end-$start+1;
    header('Content-Length: '.$left);
    if ($method==='HEAD') {fclose($handle);exit;}
    fseek($handle,$start);
    @set_time_limit(0);
    while ($left>0 && !feof($handle) && !connection_aborted()) {
        $chunk=fread($handle,min(262144,$left));
        if ($chunk===false||$chunk==='') break;
        echo $chunk;$left-=strlen($chunk);flush();
    }
    fclose($handle);
} catch(AzHttpError $e) {az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e) {az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'media_error','message'=>'This video could not be opened.']],500);}
