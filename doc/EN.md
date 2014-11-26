Webasyst module
===============

Webasyst module for interaction with [retailCRM](http://www.retailcrm.ru) through [REST API](http://retailcrm.ru/docs/Разработчики).

### Module allows

* Exchange the orders with retailCRM
* Configure relations between dictionaries of retailCRM and Webasyst (statuses, payments, delivery types and etc)
* Generate [ICML](http://docs.retailcrm.ru/index.php?n=Разработчики.ФорматICML) (Intaro Markup Language) for catalog loading by retailCRM

### Installation

#### Marketplace

* Install the module through Webasyst Marketplace

#### Settings

1. Go to the component "Shop"
2. Click on the link "plugins"
3. In the left column, click on the link "Retailcrm"
4. Make initial settings and click Save
5. After saving, additional tabs open, carefully adjust it and click Save
6. On the first tab, activate the module

#### Export Catalog

Setup cron to export catalog on schedule

```bash
* */12 * * * /path/to/php /path/to/cli.php shop retailcrmIcml
```

#### Import new orders from CRM to shop

Setup cron to exchange orders between CRM and your store on a schedule

```bash
*/15 * * * * /path/to/php /path/to/cli.php shop retailcrmHistory
```