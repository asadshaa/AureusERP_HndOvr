# Employee Onboarding & Candidate Conversion

**Status:** Implemented & Verified  
**Parent Note:** [[HR Overview]]  

---

## 1. Candidate Conversion Workflow

The recruitment-to-onboarding pipeline transitions applicants from hiring pipelines into active employee records.

```text
Applicant in Recruitment Pipeline (recruitments_applicants)
         │
         ▼ (Passes interviews, Offer Letter Accepted)
CandidateConversionService::convert()
         │
         ▼
1. Creates or links Partner (Webkul\Partner\Models\Partner)
2. Creates User account with 'Employee' role
3. Creates Employee record (Webkul\Employee\Models\Employee)
4. Assigns Company, Department, Manager (parent_id), and Work Location
5. Generates initial Welcome / Onboarding plan
```

---

## 2. Pre-Onboarding & Information Verification

- **Identity & Compliance Verification:** Captures CNIC / National ID, passport number, personal tax ID (NTN), social security / EOBI identifiers, and emergency contacts.
- **Sensitive Fields Protection:** Identity identifiers and bank payout accounts are subject to [[Sensitive Approvals]]. Any subsequent edit by an employee or manager triggers an audit request to HR before changes apply to the employee master.

---

## 3. Source References

```text
plugins/webkul/recruitments/src/Services/CandidateConversionService.php
plugins/webkul/employees/src/Services/EmployeeSensitiveChangeService.php
tests/Feature/EmployeeOnboardingTest.php
```
