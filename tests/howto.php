<?php
// Acceptance T1-T9 through the real pipeline: Guard flags/probes, Answerer (OpenAI), output filter, question log
// Run from a Joomla site with Ask the Site installed and set up (real AI calls, a few cents):
//   php tests/howto.php /path/to/joomla
// How-to answers (G8–G10 of docs/guides-spec.md): one indexed page is turned into a guide for the
// test (its index entry only, not the page) and indexed again from the page afterwards.
$site = rtrim($argv[1] ?? '', '/');

if ($site === '' || !is_file($site . '/configuration.php')) {
    fwrite(STDERR, "Usage: php tests/howto.php /path/to/joomla\n");
    exit(2);
}

const _JEXEC = 1;
define('JPATH_BASE', $site . '/administrator');
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
$container = \Joomla\CMS\Factory::getContainer();
foreach (['session.web', 'session', 'JSession', \Joomla\CMS\Session\Session::class, \Joomla\Session\Session::class, \Joomla\Session\SessionInterface::class] as $alias) {
    $container->alias($alias, 'session.web.administrator');
}
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/administrator/'; $_SERVER['SCRIPT_NAME'] = '/administrator/index.php';
$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
\Joomla\CMS\Factory::$application = $app;
$app->createExtensionNamespaceMap();
use Gss\Component\Mcpask\Administrator\Ask\{Settings, Answerer, Retriever, Indexer, Document};
use Gss\Component\Mcpask\Administrator\Ask\Source\Sources;
$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
$s = Settings::load(); $src = Sources::current($db, $s); $ix = new Indexer($db, $s);
// borrow one public item: its index entry becomes a guide for the test, then is put back
$id = (int) $db->setQuery("SELECT item_id FROM #__mcpask_documents WHERE source='flexicontent' AND status='ok' ORDER BY item_id DESC LIMIT 1")->loadResult();
$orig = $src->load($id);
$guide = '<div class="guide" data-guide="1"><p class="guide-summary">完成後，FormWizard 表單可以讓同仁在手機上填寫並送出簽核。</p>'
  . '<h2 id="prerequisites">前置條件</h2><ul><li>已有 FormWizard 管理者帳號</li></ul>'
  . '<h2 id="step-1">步驟 1：登入 FormWizard 後台</h2><p>以管理者帳號登入 FormWizard，點選右上角「管理」。</p>'
  . '<h2 id="step-2">步驟 2：建立新表單</h2><p>點選「表單設計」→「新增表單」，輸入表單名稱後按「建立」。</p>'
  . '<h2 id="step-3">步驟 3：設定簽核關卡</h2><p>在「簽核流程」頁籤按「新增關卡」，指定簽核人或職務，可設定多關。</p>'
  . '<h2 id="step-4">步驟 4：發佈表單</h2><p>按「發佈」，選擇可使用的部門，表單就會出現在同仁的「我的表單」。</p>'
  . '<h2 id="verify">完成後確認</h2><p>用同仁帳號開啟「我的表單」，看到新表單即完成。</p>'
  . '<p class="guide-next">下一份：設定 FormWizard 簽核代理人</p></div>';
$ix->index(new Document('flexicontent', $id, 'FormWizard 表單從建立到上線', $orig->link, '*', 1, $guide));
echo "guide indexed into item #$id: ", $db->setQuery("SELECT chunks FROM #__mcpask_documents WHERE source='flexicontent' AND item_id=$id")->loadResult(), " passages\n\n";
$ask = function (string $q) use ($db, $s, $src) {
  $a = new Answerer($s, new Retriever($db, $s, $src), $src, 'GSS Cloud', ['searching' => '', 'found' => '%d', 'nothing' => '(nothing)', 'again' => '%s', 'reading' => '%s']);
  $t = ''; $a->answer($q, [], 'zh-TW', function ($e, $d) use (&$t) { if ($e === 'delta') $t .= $d['text']; return true; });
  return $t;
};
try {
  $cases = ['G8' => 'FormWizard 怎麼建立表單並上線？', 'G9' => 'FormWizard 的簽核關卡要在哪裡新增？', 'G10' => 'BizForm 如何匯出表單資料'];
  // Repeat to see how steady the model is: php tests/howto.php /path/to/joomla G9 3
  if (isset($argv[2], $cases[$argv[2]])) { $cases = array_fill_keys(array_map(fn ($n) => $argv[2] . '#' . $n, range(1, max(1, (int) ($argv[3] ?? 1)))), $cases[$argv[2]]); }
  foreach ($cases as $name => $q) {
    $t = $ask($q);
    $order = []; foreach (['登入', '建立', '簽核', '發佈'] as $w) { $p = mb_strpos($t, $w); $order[$w] = $p === false ? -1 : $p; }
    echo "== $name: $q\n", $t, "\n-- positions: ", json_encode($order, JSON_UNESCAPED_UNICODE), "\n\n";
  }
} finally {
  $ix->index($orig);
  echo "restored item #$id: ", $db->setQuery("SELECT title FROM #__mcpask_documents WHERE source='flexicontent' AND item_id=$id")->loadResult(), "\n";
}
