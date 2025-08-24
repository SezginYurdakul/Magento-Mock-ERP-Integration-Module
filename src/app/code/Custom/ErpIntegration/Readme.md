## Admin Panel Setting

You can set the JSON file path from the Magento admin panel:

- Go to **Stores > Configuration > General > ERP Integration > General Settings**
- Set the **ERP Products JSON File Path** (relative to Magento root, e.g. var/import/erp_products.json)
- This value will be used by default if no file path is provided to the CLI command.


# Custom_ErpIntegration Magento 2 Module

## System Requirements

- **Magento Version:** 2.4.x or higher
- **PHP Version:** 7.4 or higher
- **MySQL Version:** 5.7 or higher
- **Required PHP Extensions:** curl, mbstring, json, xml
- **Composer:** Must be installed

## Installation

1. Copy the module files to:
        ```
        app/code/Custom/ErpIntegration
        ```
2. In your Magento root directory, run:
        ```bash
        php bin/magento module:enable Custom_ErpIntegration
        php bin/magento setup:upgrade
        php bin/magento cache:flush
        ```

## Usage

You can use the module in two ways:

### 1. Console Command

Run the following command to manually trigger the integration:

```bash
php bin/magento erp:products:update
```
If you want to specify file path, add the file path to the command
```bash
php bin/magento erp:integration:run var/import/erp_products.json

### 2. Automatic via Cron

Magento's cron system will automatically run the integration at scheduled intervals. Make sure cron is working:

```bash
php bin/magento cron:run
```

## JSON Format Examples

### Update Product
```json
{
    "action": "update",
    "sku": "24-MB01",
    "price": 200,
    "qty": 100
}
```

### Enable Product
```json
{
    "action": "enable",
    "sku": "24-MB02"
}
```

### Disable Product
```json
{
    "action": "disable",
    "sku": "24-MB03"
}
```

### Create New Product
```json
{
    "action": "new",
    "sku": "24-MB08",
    "name": "New Product 24-MB08",
    "price": 100,
    "qty": 10,
    "attribute_set_id": 4,
    "type_id": "simple",
    "status": 1,
    "visibility": 4
}
```

## Full Example (Array)
```json
[
    {
        "action": "update",
        "sku": "24-MB01",
        "price": 200,
        "qty": 100
    },
    {
        "action": "enable",
        "sku": "24-MB02"
    },
    {
        "action": "new",
        "sku": "24-MB08",
        "name": "New Product 24-MB08",
        "price": 100,
        "qty": 10,
        "attribute_set_id": 4,
        "type_id": "simple",
        "status": 1,
        "visibility": 4
    }
]
```

## Required Fields for New Product
- sku
- name
- price
- qty
- attribute_set_id
- type_id
- status
- visibility

## Notes
- The `action` field determines the operation: `update`, `disable`, `enable`, or `new`.
- For `update`, only the fields you want to change are required (e.g., price, qty).
- For `new`, all required fields must be provided.
- For `enable`/`disable`, only `sku` is required.

## Log Output Example

During the operation of this module, error and summary messages are recorded in the `var/log/magento.cron.log` file as shown below:

```
[2025-08-24T08:41:27.776080+00:00] cron.INFO: No records were processed. [] []
[2025-08-24T08:41:27.776138+00:00] cron.INFO: Reasons: [] []
[2025-08-24T08:41:27.776152+00:00] cron.INFO: Product with SKU "24-MB01" could not be updated: quantity for source "default" cannot be negative (-300.00) [] []
[2025-08-24T08:41:27.776162+00:00] cron.INFO: Product with SKU "24-MB02" could not be enabled: already enabled. [] []
[2025-08-24T08:41:27.776170+00:00] cron.INFO: Product with SKU "24-MB14" could not be created: quantity for source "default" cannot be negative (-999.00) [] []
```

Each reason is logged on a separate line with a timestamp.

> **Note:** The same summary and reason messages are shown both in the console output and in the `var/log/magento.cron.log` file. This is intentional for full transparency: users running the CLI see the same actionable information as what is recorded in the logs. 