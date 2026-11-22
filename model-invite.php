<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
if(!az_is_installed())az_redirect('install.php');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex,nofollow');
$token=is_string($_GET['token']??null)?$_GET['token']:'';
$invite=az_casting_invite_detail($token);$error='';$user=az_current_user();
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        az_require_csrf();
        $account=az_casting_redeem_invitation($token,$_POST['password']??null,$user);
        az_login_user($account);az_redirect('dashboard.php');
    }catch(AzHttpError $e){http_response_code($e->status);$error=$e->getMessage();}
    catch(Throwable $e){az_log_exception($e);http_response_code(500);$error='Unable to accept this invitation right now.';}
    $invite=az_casting_invite_detail($token);
}
$existing=false;
if($invite){$q=az_db()->prepare('SELECT id FROM users WHERE email=?');$q->execute([$invite['email']]);$existing=$q->fetchColumn()!==false;}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Join the AZHottie model team</title><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/casting.css"></head><body class="az-casting-body"><?php az_render_universal_header('apply',$user);?><main class="az-invitation-layout"><section class="az-casting-card"><p class="az-kicker">PRIVATE MODEL INVITATION</p><h1>Your next chapter <em>starts here.</em></h1><?php if($error!==''):?><p class="az-error" role="alert"><?=az_h($error)?></p><?php endif;?>
<?php if(!$invite):?><p>This invitation has expired, was already used, or is no longer active. Contact the AZHottie team to request a fresh link.</p><a class="az-casting-outline" href="index.php">Return home</a>
<?php else:?><p>Welcome, <strong><?=az_h($invite['applicant_name'])?></strong>. Your casting application was approved. Accept this invitation to activate your model account and private creative workspace.</p>
<?php if($existing && (!$user || strtolower($user['email'])!==strtolower($invite['email']))):?><p>There is already a member account with your application email. Sign in with that account and reopen this invitation link to activate your model role.</p><a href="login.php" class="az-primary-button">Sign into your member account</a>
<?php else:?><form method="post" action="model-invite.php?token=<?=az_h($token)?>"><input type="hidden" name="csrf" value="<?=az_h(az_csrf_token())?>"><?php if(!$existing):?><label class="az-admin-field">Create a password for your account<input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required></label><p class="az-muted">Use 8–72 characters. Your account will include model, fan and customer roles.</p><?php else:?><p class="az-muted">You're signed in as <?=az_h($user['email']??'')?>. Accept to add your approved model role to this account.</p><?php endif;?><button class="az-primary-button" type="submit">Activate my model account</button></form><?php endif; ?><?php endif;?></section></main></body></html>
