<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
putenv('APP_ENV=local');$_SERVER['HTTP_HOST']='127.0.0.1';
$e=\Verdant\Support\Format::escape(...);$money=\Verdant\Support\Format::money(...);$label=\Verdant\Support\Format::label(...);$image=\Verdant\Support\Format::imagePath(...);
$pageSections=[];
foreach(\Verdant\Storefront\SectionRegistry::defaults() as $row){if($row['page_key']!=='home')continue;$pageSections[]=$row+['definitions'=>[],'products'=>[],'rentals'=>[],'collections'=>[],'collection_id'=>null,'arrangement_id'=>null];}
ob_start();require dirname(__DIR__).'/app/Views/landing.php';$html=ob_get_clean();
$check=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);};
$check(strpos($html,'data-vision-section="hero"')<strpos($html,'data-vision-section="spaces"'),'Hero precedes spaces.');
$check(strpos($html,'data-vision-section="configuration-entry"')<strpos($html,'data-vision-section="rental-collection"')&&strpos($html,'data-vision-section="rental-collection"')<strpos($html,'Ways to shop'),'Configuration, rentals, service order.');
$check(str_contains($html,'Shop for workspaces') && str_contains($html,'Explore gatherings') && !str_contains($html,'/collections/workspaces') && !str_contains($html,'/collections/gatherings'),'Unimplemented spaces keep the reference links without inventing routes.');
$check(str_contains($html, 'for a brighter'), 'Hero keeps its right-hand annotation.');
$pageSections=array_reverse($pageSections);ob_start();require dirname(__DIR__).'/app/Views/landing.php';$reversed=ob_get_clean();
$check(strpos($reversed,'Ways to shop')<strpos($reversed,'data-vision-section="hero"'),'Managed service section ordering is retained.');
$pageSections=array_values(array_filter($pageSections,static fn($row)=>$row['section_type']!=='product_grid'));ob_start();require dirname(__DIR__).'/app/Views/landing.php';$hidden=ob_get_clean();
$check(!str_contains($hidden,'id="shop"'),'Hiding managed products does not resurrect a copied static grid.');
$pageSections=[];ob_start();require dirname(__DIR__).'/app/Views/landing.php';$empty=ob_get_clean();$check(trim($empty)==='','All-hidden has no fallback resurrection.');
$pageSections=[['section_type'=>'../../private']];ob_start();require dirname(__DIR__).'/app/Views/landing.php';$unknown=ob_get_clean();$check(trim($unknown)==='','Unregistered stored template cannot select a file.');
echo "Landing presentation checks: 8 passed, 0 failed.\n";
