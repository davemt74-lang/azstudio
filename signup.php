<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(!az_is_installed()){az_redirect('install.php');}
if($user=az_current_user()){az_redirect(az_has_role($user,'admin')?'library.php':'calendar.php');}
$error='';$name=is_string($_POST['name']??null)?$_POST['name']:'';$email=is_string($_POST['email']??null)?$_POST['email']:'';
$chosen=is_array($_POST['roles']??null)?$_POST['roles']:['fan','customer'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $lock=null;
    try{
        az_require_csrf();$lock=az_community_lock();
        $user=az_create_member(['name'=>$name,'email'=>$email,'password'=>$_POST['password']??null,'roles'=>$chosen,'adultConfirmed'=>$_POST['adultConfirmed']??false]);
        az_login_user($user);flock($lock,LOCK_UN);fclose($lock);$lock=null;az_redirect('calendar.php');
    }catch(AzHttpError $exception){$error=$exception->getMessage();http_response_code($exception->status);}
    catch(Throwable $exception){az_log_exception($exception);$error='Your account could not be created. Please try again.';http_response_code(500);}
    finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Join AZHottie</title><link rel="stylesheet" href="assets/auth.css"><link rel="stylesheet" href="assets/accounts.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"></head><body class="signup-body">
<?php az_render_universal_header('signup', null, true); ?><main class="auth-shell"><a class="auth-brand" href="index.php">AZHOTTIE <span>YOUR NEXT CHAPTER</span></a><section class="auth-card"><p class="eyebrow">Join the community</p><h1>One account. Your roles.</h1><p>Discover members-only events and classes, book an appointment, and keep your AZHottie activity together.</p><?php if($error!==''):?><div class="notice error" role="alert"><?=az_h($error)?></div><?php endif;?><form method="post" action="signup.php"><input type="hidden" name="csrf" value="<?=az_h(az_csrf_token())?>"><div class="field"><label for="signup-name">Display name</label><input id="signup-name" name="name" maxlength="120" autocomplete="name" value="<?=az_h($name)?>" required></div><div class="field"><label for="signup-email">Email address</label><input id="signup-email" name="email" type="email" maxlength="254" autocomplete="username" value="<?=az_h($email)?>" required></div><div class="field"><label for="signup-password">Password</label><input id="signup-password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required><small>Use 8–72 characters.</small></div><fieldset class="signup-roles"><legend>How would you like to join?</legend><?php foreach(['fan'=>'Fan · follow the brand','customer'=>'Customer · events and appointments','model'=>'Model · request team access','professional'=>'Professional · request service-provider access'] as $role=>$label):?><label><input type="checkbox" name="roles[]" value="<?=az_h($role)?>" <?=in_array($role,$chosen,true)?'checked':''?>><span><?=az_h($label)?></span></label><?php endforeach;?><small>You can have several roles. Fan/customer access starts immediately. An administrator approves model and professional access. A customer role is included if you request only a team role.</small></fieldset><label class="signup-adult"><input type="checkbox" name="adultConfirmed" value="1" required <?=!empty($_POST['adultConfirmed'])?'checked':''?>><span>I am 18 or older.</span></label><button type="submit" class="button">Create my account</button></form><p class="auth-actions">Already a member? <a href="login.php">Sign in</a></p></section></main></body></html>
