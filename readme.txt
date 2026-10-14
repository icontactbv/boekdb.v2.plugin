=== BoekDB.v2 ===
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: Proprietary

Fetches and displays book data provided by the BoekDBv2 CMS.

== Description ==

This plugin imports books, contributors, series and subjects from BoekDB into WordPress, and keeps them up to date. It is proprietary software, developed for VBK Uitgevers B.V. by Icontact B.V. Contact Jacqueline Remmers (jremmers@vbku.nl) for more information.

== Changelog ==

= 1.2.0 =
* Security: the settings screen now requires the capability the menu requires and a valid form token. Anyone could delete an etalage, and the books that came with it.
* Security: the meta boxes of a book are only saved for a book, with a valid form token, by someone who may edit it, and their texts are escaped on the edit screen. The alternate url has to be one on the site, also when it was stored before.
* Fix: saving a book without changing its annotatie or flaptekst no longer marks it as overwritten when it holds a quote, several lines, or html that is filtered for the user, and backslashes in them survive.
* Fix: an etalage name with a quote in it is stored without a backslash.
* Fix: an etalage without a name or with an unknown api key is no longer stored after the error, and adding one while BoekDB is out of reach shows an error instead of a fatal error.
* Fix: the notice that BoekDB cannot be reached shows its text in bold instead of the html tags.
* The example templates escape what they print.
* Fix: large files no longer stall an import. Downloads stream to disk instead of being held in memory.
* Fix: an image or pdf that is missing, empty or not a valid file is downloaded again when its book is next imported.
* Fix: an etalage left marked as running is taken back after ten minutes, and a batch that dies hands it back and schedules the next one at once.
* Fix: a batch is 25 books instead of 100. A shorter batch is less likely to outlast the time a server gives a process, and the offset shows progress sooner.
* New: the settings screen says which etalage is being worked on and which book of its batch it is on, what waits and when the next batch comes, and when each etalage was last finished. Etalages waiting with no batch scheduled get one when the screen is opened.
* Fix: one book that cannot be processed no longer ends the batch. Skipped books are fetched again afterwards, and listed on the settings screen with their isbns.
* Fix: a book that ends the process itself, through memory or a hook that exits, is written down as failed and the next batch starts after it, instead of the batch dying on it again and again. Retries count an attempt before they start, so one that keeps ending the process stops after three.
* Fix: Stop keeps an import stopped, and keeps its place instead of starting over.
* Fix: Reset starts the etalage over from its first book, also after Stop kept its place.
* Fix: a finished run records when it started, so changes made while it ran are not skipped.
* Fix: the daily cleanup runs again, and keeps away from an import in progress.
* Fix: series images are stored where they are read back, so they show up at all.
* Fix: a book in a series without a title no longer raises warnings.
* Fix: a file shared by several books or authors survives the removal of one of them.
* Fix: the cleanup only removes media of books and terms of the plugin's own taxonomies. Media of other posts deleted in the same cron run, and terms of taxonomies other plugins add to books, are left alone.
* Fix: the cleanup stops when the etalages or books cannot be read, instead of taking a failed query for an empty site and deleting every book.
* Fix: review quotes switched off on the site stay off after an import.
* Fix: books without an nstc are marked as primary under the name every overview reads, and existing ones are migrated.
* Fix: cached permalinks are cleared when a book becomes the primary edition or joins another etalage.
* Fix: an etalage whose selection emptied out loses its books, and unused authors, series and subjects are cleaned up.
* Fix: a list of isbns that drops to nothing or to less than half while the selection stayed the same is held back for one round, so a fault at BoekDB does not remove the books of an etalage. A second such list an hour later is believed.
* Fix: the API is called at www.boekdbv2.nl, which the bare domain now redirects to.
* Performance: an unchanged batch of six editions went from 440 to 182 queries, and recognising a file the site already has from 11 to 5.
* PHP 7.4 through 8.5. Requires WordPress 6.4 or newer.
