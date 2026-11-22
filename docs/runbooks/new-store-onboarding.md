# New Store Onboarding Runbook

Use this runbook to provision a new store in the NS Ultimate Admin. Complete and verify each step before continuing. The order matters because later configuration depends on store UUIDs, memberships, permissions, devices, and provider bindings created earlier.

## Before starting

Verify that the operator can sign in to the Admin with administrator access. If roles or menus are missing, the deployment administrator must confirm that the corresponding authorization seed data is present.

Grant the least privilege needed for each person's work. A store membership and a permission assignment are different:

- **Membership** determines whether an account can access a store.
- **Role assignment** determines which operations the account can perform after selecting that store.

Record the following values for later steps:

- Store name, code, and UUID
- Account name and User UUID
- Membership role and assigned business roles
- Printer device and template UUIDs
- Huifu merchant and enabled Store payment methods
- WeChat applications, merchant, and Store bindings

## 1. Create the store

Open **Store Management > Store > New**.

1. Enter a unique **Code**.
2. Enter the display **Name**.
3. Set **Status** to Active for an operating store. Use Suspended during preparation or Closed only for a permanent closure.
4. Set **Timezone**, normally `Asia/Shanghai`.
5. Fill in contact and address information as required.
6. Configure order, inventory, and fulfillment settings for the store's workflow.
7. Do not put Huifu credentials in the legacy Payment Setting field. Bind Huifu through the dedicated pages in step 7.

After saving, open the store detail and record its **UUID**.

Done when the store is active, the timezone is correct, the UUID is recorded, and no duplicate code exists.

## 2. Add the store member account

### Create or confirm the user

Open **User Management > User**.

- For an existing account, search by username and record the User UUID.
- For a new account, create it with username, email, phone, and an initial password.
- Ordinary staff should keep `ROLE_USER`; do not grant `ROLE_ADMIN` or `ROLE_SUPER_ADMIN`.

### Add the account to the store

Open **Store Management > Member Management**.

1. Select the target store.
2. Choose **Add or update member**.
3. Select the user account.
4. Choose the membership role: Owner, Manager, Clerk, or Fulfillment.
5. Save and confirm the membership status is Active.

The membership role identifies the person's store relationship. It does not grant every business operation. Each action still requires a matching store-scoped role assignment from step 3.

Keep at least one active Owner. The backend prevents suspending or revoking the last active Owner.

Done when the account is listed as an active member of the target store and can select that store after signing in.

## 3. Grant member permissions

Open **Authorization > Assignment > New**.

1. Select the user.
2. Select a store-scoped business role.
3. Set Scope Type to **Store**.
4. Select the target store UUID.
5. Save.
6. Repeat for each additional capability the account needs.

Typical roles include catalog manager/operator, availability operator, order reader/operator, refund operator, print operator, counter manager/session operator, counter order editor/canceller/merger, Huifu manager, and WeChat manager.

Assign roles by job responsibility instead of granting every permission. Refunds, payment closure, and catalog deletion are sensitive capabilities and should have a named owner.

Done when each assignment has the correct user, a store-scoped role, and the target store UUID, and the user can see only the operations they are authorized to perform after signing in again or reconnecting the store.

## 4. Configure store products

Open **Product Management**.

### Categories

Create categories under **Product Category**, or use the list action to copy categories from another store. When copying, verify hierarchy, names, slugs, and enabled state at the target store.

### Products

Open **Product > New** and set the product name, description, category, target **Store**, sort order, recommendation level, sold-out state, status, cover, subtitle, product settings, and specifications.

The Store field must be the new store, not empty. Configure at least one valid specification and price for products that are sold by variant.

Products can also be copied from another store. After copying, verify the store binding, category mapping, specifications, prices, status, sold-out state, kitchen settings, and sale windows.

Done when the target store filter shows the complete catalog and every saleable product has valid pricing and availability.

## 5. Add printer infrastructure

### Printer account

Open **Printer Management > Printer Accounts > New** and enter the provider account name, Developer ID, and Developer Key. Xpyun is the currently registered production adapter. Credentials are encrypted at rest and are not returned by the API.

### Printer device

Open **Printer Management > Printer Devices > New**.

1. Select the printer account.
2. Enter the device name and actual serial number.
3. Select Receipt or Label to match the hardware.
4. Select the target store.
5. Optionally set model and label dimensions.
6. Enable the device.

Saving a new device registers it with the configured provider. If registration fails, do not create duplicate device records; check the account, serial number, and provider connectivity first.

### Template

Open **Printer Management > Printer Templates > New**. Create a template whose kind matches the device, choose one-ticket-per-order or per-unit output, configure any optional item filter, and preview the template before saving it for production use.

Done when the account and device are enabled, the device is bound to the correct store, provider status is available, and a matching template renders successfully.

## 6. Add order print rules

Open **Printer Management > Order Print Rules > New**.

1. Select the target store.
2. Enter a clear rule name.
3. Keep the rule disabled until all fields are verified, then enable it.
4. Select one or more events: order confirmed, payment succeeded, store accepted, or manual print.
5. Choose all/any condition matching and add conditions if needed.
6. Select a device belonging to this store.
7. Select a template whose kind matches the device.
8. Set the copy count.

Rules enqueue print requests; they do not print by themselves. Run the dispatcher as a scheduled task or supervised worker:

```sh
php integration/backend/bin/console app:printer:dispatch-orders
```

Done when the enabled rule has the correct store, events, conditions, device, template, and copies, the dispatcher is running, and a test order creates a visible print request and terminal job result.

## 7. Configure Store Huifu payments

Huifu requires all of the following: an enabled provider profile, an enabled merchant with the required capability, an active merchant-to-store grant, and an enabled Store Huifu setting.

### Confirm the platform configuration

Open **Huifu Configuration** and verify the provider profile and merchant. The merchant must be enabled and its capabilities must include the methods the store will use (`jspay`, `native`, or `quickpay`).

### Grant the merchant to the store

Open **Huifu Configuration > Huifu Merchant Grants > New**. Select the merchant and target store, then save.

### Configure Store Huifu settings

Open **Huifu Configuration > Store Huifu Settings > New**.

1. Select the target store.
2. Select the granted merchant.
3. Enable the setting.
4. Set Allowed payment methods to a JSON array matching the merchant capabilities, for example `["native", "jspay"]`.
5. Select a default method included in the allowed list.
6. Enable POS only when the store actually uses a configured Huifu POS device.

`jspay` requires the payer to sign in through the store's configured WeChat application so the backend can resolve the OpenID. `native` returns QR payment content. `quickpay` has adapter code but requires product-contract validation before production use.

Done when the public payment summary returns the expected `channels` and `defaultChannel`, and a test order can create a real payment attempt rather than only showing a button.

## 8. Configure Store WeChat

### WeChat application

Open **WeChat Configuration > WeChat Applications > New**. Create the Mini Program and/or Official Account profile with its AppID and AppSecret. Add the server Token and EncodingAESKey when the selected integration requires them.

### WeChat merchant

If the store uses WeChat Pay, open **WeChat Configuration > WeChat Merchants > New** and configure the merchant ID, direct/service-provider mode, API v3 key, merchant private key and certificate, platform certificate, and service-provider fields when applicable.

### Store binding

Open **WeChat Configuration > Store WeChat Settings > New**.

1. Select the store.
2. Bind the Mini Program application if needed.
3. Bind the Official Account application if needed.
4. Bind the WeChat merchant for WeChat Pay scenarios.

At least one WeChat application is required. Login requests must include the correct `store_uuid`; the backend resolves the AppID and credentials through this binding.

Done when the user can log in through the target store's application, the resulting OpenID belongs to that application, and the required WeChat payment flow can complete and be confirmed by the server.

## Final acceptance checklist

- [ ] Store code is unique, active, and in the correct timezone.
- [ ] The operator account has an active membership in the store.
- [ ] The account has only the required store-scoped business roles.
- [ ] Products, categories, specifications, prices, and images are correct.
- [ ] Printer account, device, template, and rule are configured.
- [ ] The print dispatcher is running.
- [ ] Huifu merchant capability, grant, and Store settings are consistent.
- [ ] Payment channels and default channel match the intended configuration.
- [ ] Store WeChat application and merchant bindings are correct.
- [ ] A normal staff account completes login, product read, order, print, and payment tests.
- [ ] An unauthorized account cannot perform refunds, payment closure, or catalog changes.

## Troubleshooting

### The account can select the store but cannot perform actions

Check the step 3 role assignments. Membership alone does not grant operations. Sign in again or reconnect the store to refresh the permission snapshot.

### Products are not attached to the target store

Check the product's Store field. New store products must not leave it empty. After copying products, filter the list by the target store and verify the result.

### A print rule is enabled but no printer outputs a ticket

Check device and account status, device/template kind compatibility, rule enabled state, event and condition matching, and whether the print dispatcher is running.

### No payment methods are available

Check the Huifu provider, merchant status and capabilities, merchant-to-store grant, Store Huifu setting, allowed methods, and default method.

### WeChat login or JSAPI payment fails

Check the store application binding, AppID, Store UUID supplied by the client, AppSecret, and whether the payer has an OpenID for the exact AppID configured on the store.
