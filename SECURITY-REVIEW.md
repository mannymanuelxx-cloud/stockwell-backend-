# Security review

## Finding

**Medium — Public product catalog exposes internal business data**  
**Confidence:** 9/10  
**Location:** [`ProductModel.php`](./app/models/ProductModel.php#L198) and [`routes.php`](./app/config/routes.php#L109)

The public storefront catalog includes `reorder_level`, `supplier_id`, and all-time `units_sold` in product responses. Because the catalog endpoint is unauthenticated, visitors can retrieve these internal fields and infer inventory thresholds, supplier references, and historical sales volumes.

**Recommended remediation:** Return only storefront-facing fields from the public catalog. Keep supplier references, reorder thresholds, and sales aggregates in an appropriately authorized endpoint.

## Review scope and limitations

The review was read-only and focused on authentication, API access, payments and refunds, and input handling in the current frontend and backend source. The backend test suite was reported as passing. No `.env` files or credentials were read, and no production endpoints were probed. This source review does not certify the deployed runtime or integrations.
