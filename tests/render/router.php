<?php
/* Local render harness (php -S, build host only). Unraid's stylesheets, the page body run as PageBuilder does. */
$theme = getenv('THEME') ?: 'black';
$webroot = getenv('WEBROOT');
$plug = "$webroot/plugins/unraid-chronostasis";
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/plugins/unraid-chronostasis/include/(status|action)\.php$~', $uri, $m)) { require "$plug/include/{$m[1]}.php"; return true; }
if (strpos($uri, '/plugins/') === 0 || strpos($uri, '/webGui/') === 0) return false;
$docroot = $webroot;
$var = @parse_ini_file(getenv('CS_VARINI')) ?: [];
$page = isset($_GET['about']) ? 'ChronostasisAbout.page' : 'ChronostasisCheck.page';
$legacy = getenv('WEBGUI') === '6.12';
$styles = $legacy ? ['default-fonts', 'default-cases', 'font-awesome', "default-$theme", "dynamix-$theme"]
                  : ['default-fonts', 'default-cases', 'font-awesome', 'default-color-palette', 'default-base', 'default-dynamix', "themes/$theme"];
?><!DOCTYPE html><html lang="en" class="Theme--<?=htmlspecialchars($theme)?>"><head><meta charset="utf-8">
<?php foreach ($styles as $c): ?><link rel="stylesheet" href="/webGui/styles/<?=$c?>.css"><?php endforeach; ?>
<style>body{padding:16px;background:var(--background-color,#111);color:var(--text-color,#ddd)}</style></head><body>
<?php eval('?>' . explode("\n---\n", file_get_contents("$plug/$page"), 2)[1]); ?>
<?php if (isset($_GET['do'])): ?>
<script>/* test only: steps of ?do=, one a beat. A selector clicks; find:TEXT presses Next until the walkthrough shows
   TEXT; pick:VALUE chooses in the pairing list. */
(function(){var q=<?=json_encode(explode(',', (string)$_GET['do']))?>;
function nextBtn(){return document.getElementById('cs-next');}
function n(){if(!q.length)return;var s=q[0];
if(s.indexOf('find:')===0){if(document.getElementById('cs-walk').textContent.indexOf(s.slice(5))>=0)q.shift();else{var x=nextBtn();if(x)x.click();}}
else if(s.indexOf('pick:')===0){var o=document.getElementById('cs-other'),m=document.getElementById('cs-pairshow');if(o){o.value=s.slice(5);o.dispatchEvent(new Event('change'));q.shift();}else if(s==='pick:not paired')q.shift();else if(m)m.click();}
else{var e=document.querySelector(s);if(e){q.shift();e.click();}}
setTimeout(n,120);}setTimeout(n,500);})();
</script>
<?php endif; ?>
</body></html>
