<?php
// Unit tests for the images Chunker keeps: no Joomla, no network
const _JEXEC = 1;
require dirname(__DIR__) . '/src/com_mcpask/administrator/src/Ask/Chunker.php';
use Gss\Component\Mcpask\Administrator\Ask\Chunker;
$root = sys_get_temp_dir() . '/chunkroot'; @mkdir("$root/images/guide", 0777, true); touch("$root/images/guide/step1.png"); touch("$root/images/a b.jpg");
$cases = [
  'relative' => ['<img src="images/guide/step1.png" alt="登入">', 'https://www.gsscloud.com/', true],
  'root-relative' => ['<img src="/images/guide/step1.png">', 'https://www.gsscloud.com/', true],
  'absolute own https' => ['<img src="https://www.gsscloud.com/images/guide/step1.png">', 'https://www.gsscloud.com/', true],
  'absolute own http' => ['<img src="http://www.gsscloud.com/images/guide/step1.png">', 'https://www.gsscloud.com/', true],
  'protocol-relative own' => ['<img src="//www.gsscloud.com/images/guide/step1.png">', 'https://www.gsscloud.com/', true],
  'subfolder site' => ['<img src="/sub/images/guide/step1.png">', 'https://x.com/sub/', true],
  'absolute own, url unknown' => ['<img src="https://www.gsscloud.com/images/guide/step1.png">', '', false],
  'lookalike host' => ['<img src="https://www.gsscloud.com.evil.example/images/guide/step1.png">', 'https://www.gsscloud.com/', false],
  'other host' => ['<img src="https://evil.example/images/guide/step1.png">', 'https://www.gsscloud.com/', false],
  'missing file' => ['<img src="images/missing.png">', 'https://www.gsscloud.com/', false],
  'traversal' => ['<img src="images/../images/guide/step1.png">', 'https://www.gsscloud.com/', false],
  'query' => ['<img src="index.php?x=images/guide/step1.png">', 'https://www.gsscloud.com/', false],
  'javascript' => ['<img src="javascript:alert(1)//step1.png">', 'https://www.gsscloud.com/', false],
  'data' => ['<img src="data:image/png;base64,AAAA">', 'https://www.gsscloud.com/', false],
  'space in name' => ['<img src="images/a b.jpg">', 'https://www.gsscloud.com/', true],
];
$fail = 0;
foreach ($cases as $name => [$html, $url, $want]) {
  $text = implode("\n", array_column(Chunker::chunks('<p>文字</p><p>' . $html . '</p>', 400, ['folder' => $root, 'url' => $url]), 'text'));
  $got = (bool) Chunker::images($text);
  $fail += $got === $want ? 0 : 1;
  printf("%s %-28s %s\n", $got === $want ? 'PASS' : 'FAIL', $name, json_encode(Chunker::images($text)));
}
$web = implode("\n", array_column(Chunker::chunks('<p>x</p><img src="images/guide/step1.png">', 400, null), 'text'));
echo (Chunker::images($web) ? 'FAIL' : 'PASS'), " other sites keep no images\n";
echo $fail ? "$fail FAILED\n" : "all passed\n";
exit($fail ? 1 : 0);
