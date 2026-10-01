# Roadmap

## Eigen update-kanaal

Sites installeren de plugin met de hand uit een zip en krijgen alleen een melding dát er een nieuwe versie is. Daardoor lopen versies uiteen en heeft een beheerder ooit zijn eigen patch in de code gezet.

Haak in op `pre_set_site_transient_update_plugins`, zodat de update gewoon in het updatescherm van WordPress verschijnt. Stuur de plugin- en PHP-versie mee, dan weet de bron wat er draait.

## Cron-volgorde en de guard die eraan hangt

`BoekDB_Import::init()` plant het import-event in op het `minutely`-interval voordat `boekdb.php` dat interval registreert, dus WordPress weigert het. Er staat geen terugkerende import; dat het toch loopt komt doordat elke batch zelf een vervolg-event inplant.

Het interval eerder registreren laat dat event wél bestaan, en dan gaat de `wp_next_scheduled()`-guard in `import()` aan en blijft het bij één batch per minuut. Volgorde en guard moeten dus samen veranderen, met een keuze over het importtempo.

## Goedkoper opnieuw importeren na een filterwijziging

Andere filters zetten de importdatum terug naar 2015, waarna elk boek opnieuw wordt opgehaald. De ISBN-lijst van de API vergelijken met wat de site al heeft, laat zien welke boeken er werkelijk bij komen.
