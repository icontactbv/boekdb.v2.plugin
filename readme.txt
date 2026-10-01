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
* Fix: large files no longer stall an import. Downloads stream to disk instead of being held in memory.
* Fix: an etalage left marked as running is taken back after ten minutes, and a batch that dies hands it back at once.
* Fix: one book that cannot be processed no longer ends the batch. Skipped books are fetched again afterwards, and listed on the settings screen with their isbns.
* Fix: Stop keeps an import stopped, and keeps its place instead of starting over.
* Fix: a finished run records when it started, so changes made while it ran are not skipped.
* Fix: the daily cleanup runs again, and keeps away from an import Ok.in progress.
* Fix: series images are stored where they are read back, so they show up at all.
* Fix: a file shared by several books or authors survives the removal of one of them.
* Fix: review quotes switched off on the site stay off after an import.
* Fix: books without an nstc are marked as primary under the name every overview reads, and existing ones are migrated.
* Fix: cached permalinks are cleared when a book becomes the primary edition or joins another etalage.
* Fix: an etalage whose selection emptied out loses its books, and unused authors, series and subjects are cleaned up.
* Performance: an unchanged batch of six editions went from 440 to 182 queries, and recognising a file the site already has from 11 to 5.
* PHP 7.4 through 8.5. Requires WordPress 6.4 or newer.
