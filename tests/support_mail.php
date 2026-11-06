<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use Verdant\Notifications\EmailRenderer;
use Verdant\Support\SupportTokenCipher;
use Verdant\Support\Mail\{EmailConfig, EmailDispatcher, LocalCaptureTransport};
use Verdant\Persistence\{ConnectionFactory, DatabaseConfig};
if (!preg_match('/^verdant_test_[a-z0-9_]+$/D', getenv('DB_NAME') ?: '')) exit('Isolated test database required.');
$checks = 0;
$check = static function(bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$token = bin2hex(random_bytes(32));
$sealed = SupportTokenCipher::seal($token);
$check(!str_contains($sealed, $token) && SupportTokenCipher::open($sealed) === $token, 'Token ciphertext roundtrip');
$context = ['enquiry_id'=>1, 'access_token_sealed'=>$sealed];
$mail = EmailRenderer::render(['template_key'=>'support_guest_access','context'=>$context], 'http://127.0.0.1:8088');
$check(str_contains($mail['text'], '/support/access#token='.$token) && !str_contains($mail['text'], '?token='), 'Secret stays in fragment');
$reply = EmailRenderer::render(['template_key'=>'support_customer_reply','context'=>['enquiry_id'=>1]], 'http://127.0.0.1:8088');
$check(str_contains($reply['text'], '/account/enquiry?id=1') && !str_contains($reply['text'], $token), 'Account reply uses ownership-protected link');
$db = ConnectionFactory::connect(DatabaseConfig::fromEnvironment());
$tag = bin2hex(random_bytes(6));
$temp = sys_get_temp_dir().'/verdant-support-mail-'.$tag;
$put = static function(string $sql,array $args=[]) use($db):int {$s=$db->prepare($sql);$s->execute($args);$id=(int)$db->insert_id;$s->close();return $id;};
$ids=[]; $enquiry=null;
try {
    $enquiry=$put("INSERT INTO customer_enquiries(submission_key,name,email,topic,subject,message) VALUES(?,'Guest','customer@thisisphila.co.za','customer','Support mail','Hello')",[hash('sha256',$tag)]);
    $message=$put("INSERT INTO enquiry_messages(enquiry_id,author_kind,body,submission_key) VALUES(?,'guest','Hello',?)",[$enquiry,hash('sha256','message'.$tag)]);
    $json=json_encode(['enquiry_id'=>$enquiry,'access_token_sealed'=>$sealed],JSON_THROW_ON_ERROR);
    $id=$put("INSERT INTO email_outbox(message_id,recipient_email,template_key,context_json) VALUES(?,'customer@thisisphila.co.za','support_guest_access',?)",[$message,$json]);$ids[]=$id;
    $config=new EmailConfig('local_capture','verdant@example.test','Verdant','admin@thisisphila.co.za','http://127.0.0.1:8088',EmailConfig::TEST_RECIPIENTS,$temp);
    $dispatcher=new EmailDispatcher($db,$config,new LocalCaptureTransport($temp,$config));
    $check($dispatcher->releaseSelected([$id])===1,'Only selected held guest row released');
    $result=$dispatcher->dispatchSelected([$id]);
    $check($result['captured']===1,'Guest snapshot identity can receive private capture without account inference');
    $check($dispatcher->dispatchSelected([$id])['captured']===0,'Repeated dispatch does not duplicate');
    $check(is_file($temp.'/'.$id.'.eml'),'Private capture exists');
    $bad=$put("INSERT INTO email_outbox(message_id,recipient_email,template_key,context_json) VALUES(?,'admin@thisisphila.co.za',?,?)",[$message,'support_guest_access_resend_'.substr($tag.'0000000000000000',0,16),$json]);$ids[]=$bad;
    $dispatcher->releaseSelected([$bad]);
    $check($dispatcher->dispatchSelected([$bad])['held']===1,'Changed guest destination held');
    echo "Support mail checks: $checks passed; no external email sent.\n";
} finally {
    foreach($ids as$id)$put('DELETE FROM email_outbox WHERE outbox_id=?',[$id]);
    if($enquiry!==null){$put('DELETE FROM enquiry_messages WHERE enquiry_id=?',[$enquiry]);$put('DELETE FROM customer_enquiries WHERE enquiry_id=?',[$enquiry]);}
    foreach(glob($temp.'/*')?:[] as$file)unlink($file);
    if(is_dir($temp))rmdir($temp);
    $db->close();
}
