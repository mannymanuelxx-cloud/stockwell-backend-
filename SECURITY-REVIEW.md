# Security review

## Finding and remediation

**Resolved in source — Medium: Public product catalog exposed internal business data**
**Confidence:** 9/10
**Location:** [`ProductModel.php`](./app/models/ProductModel.php#L198) and [`routes.php`](./app/config/routes.php#L109)

The public storefront catalog previously included `reorder_level`, `supplier_id`, and all-time `units_sold` in product responses. Because the catalog endpoint is unauthenticated, visitors could retrieve these internal fields and infer inventory thresholds, supplier references, and historical sales volumes.

**Remediation:** Removed those internal fields and the sales aggregate join from the public catalog query. Added regression coverage to ensure they remain absent while storefront fields are retained. The source change is covered by the backend test suite, including 11 focused catalog exposure checks.

## Review scope and limitations

The original review was read-only and focused on authentication, API access, payments and refunds, and input handling in the current frontend and backend source. After remediation, the backend test suite and PHP syntax checks passed. No `.env` files or credentials were read, and no production endpoints were probed during the review. The remediation was deployed after review; the review does not certify the deployed runtime or integrations.
