# KyPanel for FOSSBilling

Version 1.0.0 · FOSSBilling 0.8 and later · PHP 8.3+

Server manager for selling hosting accounts on KyPanel servers.

## Install

1. Copy `src/library/Server/Manager/Kypanel.php` to the same folder in your FOSSBilling.
2. In KyPanel, open Ky Admin › Development › Manage API Tokens and create a token with
   **accounts** (read and change) and **packages** (read). Limit it to the IP of your FOSSBilling server.
3. In FOSSBilling, go to Products › Hosting plans and servers › New server:
   - Server manager: **KyPanel**
   - Hostname: your KyPanel server, port 7711
   - API token: the token from step 2
   - Use SSL: on once the server has a valid certificate (off accepts a self-signed one)
4. Name each hosting plan exactly like the KyPanel package (Ky Admin › Packages).

Creating, suspending, unsuspending, cancelling, password and plan changes run on the server.
"Login to control panel" opens KyPanel with a one-time link, no password needed. Reseller
orders open Ky Admin.

License: MIT

Documentation: https://docs.kypanel.com/ · Support: https://my.kypanel.com/tickets/new
