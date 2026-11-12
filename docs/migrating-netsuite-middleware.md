<!-- Consumer-specific notes. The library itself has no knowledge of these processes, datasets or fields. -->

# Migrating from `App\Services\KissflowService` (netsuite-middleware)

| Old | New |
|---|---|
| `searchByPONumber($po)` | `process('Electronic_Payment_Requisition_EPR_')->findBy('PO_Number', $po)` |
| `getKissflowId($po)` | `process('Electronic_Payment_Requisition_EPR_')->findIdBy('PO_Number', $po)` |
| `searchByEPRNumber($n)` | `process('Electronic_Payment_Requisition_EPR_')->findBy('ePR_Form_Number', $n)` |
| `searchByPRNumber($n)` / `searchPaymentRequestByPRNumber($n)` | `process('Payment_Request')->findBy('PAY_Form_Number', $n)` |
| `getItemById($id)` | `process('Electronic_Payment_Requisition_EPR_')->get($id)` |
| `getPaymentRequestById($id)` | `process('Payment_Request')->get($id)` |
| `getDatasetItems($ds)` | `dataset($ds)->all()` |
| `getDatasetItemById($ds, $name)` | `dataset($ds)->get($name)` |
| `updateDatasetItem($ds, $name, $data)` | `dataset($ds)->update($name, $data)` |
| `updateDatasetBatch($ds, $rows)` | `dataset($ds)->batch($rows)` |
| `deleteDatasetItem($ds, $name)` | `dataset($ds)->delete($name)` |
| `getBudgetItems()` | `dataset('Budgets_01')->all()` |
| `updateBudgetSpent($name, $v)` | `dataset('Budgets_01')->update($name, ['Budget_Spent' => (string) $v])` |
| `updateBudgetSpentBatch($rows)` | `dataset('Budgets_01')->batch($rows)` |
| `pushVendorsBatch($vendors, $sb)` | build the rows in the app, then `dataset($id)->withBatchUrl($url)->batch($rows)` |
| `pushEmployeesBatch($emps, $sb)` | same: `dataset('NetSuite_Employee')->batch($rows)` |
| `getUsers()` | `users()->all()` |

Behaviour differences from the old service:

1. **Errors are thrown instead of returned.** The old write methods returned `['success' => false, 'error' => ...]`. The new ones throw `ApiException`, so wrap calls in `try`/`catch` where a failure must not stop the run.
2. **The account id is configuration.** It used to be hard-coded in the process and user paths. It is now `account_id` / `KISSFLOW_ACCOUNT_ID`.
3. **SSL verification is on by default.** The old service used `verify => false`. Set `KISSFLOW_VERIFY_SSL=false` only if a host really needs it.
4. **Business mapping stays in the app.** Turning NetSuite vendors into rows, mapping countries to ISO-3, and the Budgets_01 field names are application logic and are not part of this library.
