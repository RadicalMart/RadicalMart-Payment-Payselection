# RadicalMart Payment: Payselection

**RadicalMart Payment: Payselection** is a payment gateway plugin that integrates the Payselection payment platform with
RadicalMart.

The plugin acts as an adapter between RadicalMart's payment system and the Payselection API. It allows store orders to
be processed through the external payment service while keeping order logic within RadicalMart.

---

## Purpose

This plugin provides **payment processing through Payselection** for RadicalMart orders.

Its responsibility is limited to:

* initiating payment requests
* redirecting customers to the Payselection payment flow
* receiving payment callbacks
* mapping gateway responses to RadicalMart payment states

Order lifecycle management remains controlled by RadicalMart core.

---

## What this plugin does

* Registers Payselection as a payment method
* Creates payment requests through the Payselection API
* Redirects customers to the payment interface
* Processes gateway callbacks
* Updates order payment status based on the gateway response

---

## What this plugin does NOT do

* ❌ Does not create or manage orders
* ❌ Does not calculate prices, taxes, or discounts
* ❌ Does not implement checkout workflow logic
* ❌ Does not replace RadicalMart payment orchestration

The plugin functions as a **payment gateway adapter**.

---

## Architecture role

Within RadicalMart payment architecture:

```
RadicalMart Order
        ↓
 Payment System
        ↓
 Payment Plugin (Payselection)
        ↓
 External Payment API
```

This plugin represents a **payment service integration layer**.

---

## Payment flow

1. RadicalMart prepares the order for payment.
2. The plugin creates a payment request using Payselection API.
3. The customer is redirected to the Payselection payment interface.
4. The payment is processed by the gateway.
5. Callback data is received and validated.
6. RadicalMart updates the order payment state.

---

## Configuration

The plugin exposes parameters required to connect to the Payselection service, such as:

* API credentials
* environment configuration
* callback behavior

Configuration is handled through standard Joomla plugin parameters.

---

## Usage

This plugin is intended for:

* stores using Payselection as a payment provider
* projects requiring external payment gateway integration
* installations where payment processing must be isolated from business logic

The plugin can operate alongside other payment plugins.

---

## Extensibility

Payment behavior can be extended by:

* listening to RadicalMart payment events
* implementing additional payment plugins
* adding custom payment processing logic

The plugin follows RadicalMart’s event-driven architecture.