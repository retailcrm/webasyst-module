Webasyst module
===============

Webasyst module for interaction with [retailCRM](http://www.retailcrm.ru) through [REST API](http://retailcrm.ru/docs/Разработчики).

### Module allows

* Exchange the orders with retailCRM
* Configure relations between dictionaries of retailCRM and Webasyst (statuses, payments, delivery types and etc)
* Generate [ICML](http://docs.retailcrm.ru/index.php?n=Разработчики.ФорматICML) (Intaro Markup Language) for catalog loading by retailCRM

### Installation

#### Marketplace

* You should install module through Webasyst Marketplace

#### Settings

1. Go to the component "Shop"
2. Select the tab "plugins"
3. In the left column, click on the plugin "Retailcrm"
4. Make initial settings and click Save
5. After saving open additional tabs, gently set them and press save
6. On the first tab switch module

#### Export Customers and Orders

Start with ssh script for customers and orders export

```bash
/path/to/php /path/to/cli.php shop retailcrmUpload
```

#### Export Catalog

Setup cron job for periodically catalog export

```bash
* */12 * * * /path/to/php /path/to/cli.php shop retailcrmIcml
```

#### Export new order from CRM to shop

Setup cron job for exchange between CRM & your shop

```bash
*/15 * * * * /path/to/php /path/to/cli.php shop retailcrmHistory
```