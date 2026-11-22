<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','HEAD'],true))throw new AzHttpError(405,'Use GET or HEAD to watch a saved live production.','method_not_allowed');
    $id=az_text($_GET['id']??'',32,'Recording ID');
    // An anonymous request can only see a specifically public, published show.
    // All other videos still require the existing owner/admin/member access checks.
    $row=az_is_installed()?az_live_public_media_row($id):null;
    if (!$row) {
        $user=az_require_auth(true);
        $row=az_live_recording_row($id,(int)$user['id'],az_has_role($user,'admin'));
    }
    $type=az_enum($_GET['type']??'video',['video','cover'],'Media type');
    if ($type==='cover') {
        if (empty($row['cover_storage_name']))throw new AzHttpError(404,'This broadcast has no cover image.','cover_missing');
        $file=['storage_name'=>$row['cover_storage_name']];$mime=$row['cover_mime_type'];$name='azhottie-live-cover';
    } else {
        $file=$row;$mime=$row['mime_type'];$name=$row['title'] ?: 'azhottie-live';
        if (!is_string($mime)||!str_starts_with($mime,'video/'))throw new AzHttpError(404,'This is not a playable video.','not_found');
    }
    $path=az_media_path($file);$handle=@fopen($path,'rb');
    if (!$handle)throw new AzHttpError(404,'This broadcast file is unavailable.','media_missing');
    $size=(int)(fstat($handle)['size']??0);
    if ($size<1){fclose($handle);throw new AzHttpError(404,'This broadcast file is empty.','media_missing');}
    session_write_close();while(ob_get_level()>0)ob_end_clean();@ini_set('zlib.output_compression','0');
    header('Content-Type: '.$mime);header('Accept-Ranges: bytes');header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex,nofollow');header('Cross-Origin-Resource-Policy: same-origin');
    $ext=match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/quicktime'=>'mov','video/ogg'=>'ogv',default=>'webm'};
    $safe=substr(trim(preg_replace('/[^A-Za-z0-9._ -]/','',(string)$name)??'', '. '),0,120);
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.($safe?:'azhottie-live').'.'.$ext.'"');
    $start=0;$end=$size-1;
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $range=trim((string)$_SERVER['HTTP_RANGE']);
        if ($type==='cover'||!preg_match('/\Abytes=([0-9]*)-([0-9]*)\z/D',$range,$match)||($match[1]===''&&$match[2]==='')) {
            fclose($handle);header('Content-Range: bytes */'.$size);http_response_code(416);exit;
        }
        if ($match[1]==='') { $suffix=min($size,(int)$match[2]);$start=$size-$suffix; }
        else {$start=(int)$match[1];if ($match[2]!=='')$end=min($end,(int)$match[2]);}
        if($start>=$size||$start<0||$end<$start){fclose($handle);header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    $left=$end-$start+1;header('Content-Length: '.$left);
    if ($method==='HEAD'){fclose($handle);exit;}
    @set_time_limit(0);fseek($handle,$start);
    while ($left>0&&!feof($handle)&&!connection_aborted()) {
        $part=fread($handle,min(262144,$left));if($part===false||$part==='')break;
        echo $part;$left-=strlen($part);flush();
    }
    fclose($handle);
} catch(AzHttpError $e) {
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
} catch(Throwable $e) {
    az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'media_error','message'=>'Unable to open this live production.']],500);
}
