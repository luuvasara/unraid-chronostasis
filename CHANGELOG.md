# Changelog

Copied by the build from the <CHANGES> block of unraid-chronostasis.plg; edit the .plg, not this file.

## 2026.11.07d
- The extra networks warning says them, not it.

## 2026.11.07c
- A Device the template leaves for you to fill in is not listed as moved.

## 2026.11.07b
- A field that keeps its name and type but sits at another target in the template is no longer a difference to work through or upgrade. The template author moved it; your setting is kept as it is. Each one is mentioned in one line under the app, for reference.
- A variable with a new target is therefore no longer counted as something to check.

## 2026.11.07a
- Before Unraid's form opens, it says when Apply would remove a memory limit or extra networks from the settings, and why: the form on Unraid before 7.4 has no field for them and saves only the fields it knows.
- It says what to do instead: for a memory limit, add --memory with the limit to Extra Parameters; extra networks can be connected again with docker network connect after Apply.
- Try again, going back to old settings and opening a backup ask first when this applies. Opening anyway is one more click.

## 2026.11.06h
- Your answers survive a page reload, and the walk continues where you left off. They stay in this browser and hold names only.
- Forget my answers clears them.
- Backups of apps no longer on the server are listed, and open in Unraid's form.
- A field whose required flag and default both changed is one row.
- Upgrade counts a confirmed rename once you have seen it.

## 2026.11.06g
- An app upgraded before can be upgraded again.

## 2026.11.06f
- Finish now lists every rename the template confirms, also from the other list.
- The Dismissed list of an app says Bring back too.

## 2026.11.06e
- Every button now has a tooltip that says what pressing it does.
- The two ways through the differences now say what each one holds and why to pick it.
- Add to the report is now a tick box, and it no longer reads like Include it.
- Dismissed now says Bring back, which is what pressing it does.
- A template address difference now reads in use against current.
- Previous and Next now say Previous difference and Next difference.
- Finish now offers two separate next steps, Upgrade and Report, each one app at a time.
- The report holds only what you added to it or asked about. Nothing goes in by itself.
- A moved template address or a different image now says why this plugin leaves it alone and what you can do.
- The text to copy shows the template address in use and the current one.

## 2026.11.06d
- Each field issue now shows old settings above and the template below, with your value carried over, and asks one question.
- Only in old settings: It's my own, or Report it, which adds a question about the field to the text to copy.
- New in the template: Include it, or Leave it out.
- A likely match between a new field and one in old settings is offered as a guess, with the reason. A field that kept its display name reads as moved. Choose another field to compare; a confirmed match is not asked again.
- Finish shows the upgraded settings for each app, old above and upgraded below, and opens them in Unraid's form. Nothing happens until you press Apply there.
- Old settings are saved to the flash drive first, with the exact file shown, Open folder and Download.
- If Apply fails, the error is shown if it can be found, and you can try again with the upgraded settings or go back to the old settings. Both open Unraid's form.
- Looking for the error can create a temporary container with only the image and the extra parameters. It never starts and is removed at once.
- Before Apply it says what the form would change on its own, and it will not open for a Compose container or a network that is gone.
- An app that stays on its own version says so: the comparison is with the current template.
- Values are shown only on your own server; they are never in reports or in anything you copy or paste. Masked fields show as hidden.

## 2026.11.05e
- The count now covers what is worth checking: a required field with no default that your copy does not have, and a variable with a new target.
- A different image, a changed template address and moderator notes are counted as before.
- Every other difference is still there, collapsed under each app as Other differences. A new button goes through them one at a time.
- Work through issues and the text to copy follow the same count.

## 2026.11.05d
- Fields turned off with Docker Template Fields are no longer listed twice. They are shown as turned off and not compared.
- Reuses the feed copy Fix Common Problems keeps; the path used before was never written on a real server.
- Gets the feed from the address Community Applications now uses first.
- The list says plainly that a difference is only a difference.

## 2026.11.05c
- About links to more Unraid plugins by name.

## 2026.11.05b
- Apps set to update through CA Auto Update Applications are marked auto-updated and listed first: their image moves on while the saved template stays. That plugin is optional.
- Saved templates with no container are counted, not compared, with a pointer to Appdata Cleanup Plus.

## 2026.11.05a
- A saved copy with no default is no longer reported as a changed default.
- About lists where to find more Unraid plugins.

## 2026.11.05
- First version: compares the saved template of each Docker container with its current template in Community Applications and lists what differs.
- Work through the differences one at a time, with a diagnosis and links to the app's support, project and template.
- Dismiss what you have checked; it returns if the template changes that item.
- Copy your findings and proposed mappings as text, to post wherever you choose. The plugin sends nothing.
