<?php
declare(strict_types=1);

require __DIR__.'/src/bootstrap.php';
require __DIR__.'/vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;

$target=(string)($_GET['target']??'public');
$page=$target==='screen'?'quiz-screen.php':'request.php';
$url=rtrim((string) setting('hosting_base_url', 'https://www.kr-solutions.it/vdjdesk'), '/').'/'.$page;
$builder=new Builder(writer:new SvgWriter(),writerOptions:[SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION=>true],validateResult:false,data:$url,encoding:new Encoding('UTF-8'),errorCorrectionLevel:ErrorCorrectionLevel::Medium,size:320,margin:12,roundBlockSizeMode:RoundBlockSizeMode::Margin);
$result=$builder->build();
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-KR-Quiz-URL: '.$url);
echo $result->getString();
