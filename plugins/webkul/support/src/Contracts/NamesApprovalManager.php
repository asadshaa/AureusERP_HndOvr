<?php

namespace Webkul\Support\Contracts;

use Webkul\Security\Models\User;

/**
 * An approval subject that names its own manager approver, for workflow
 * steps routed to "record_manager" (e.g. a job offer's hiring manager: the
 * job's manager, else the recruiter's line manager). There is no employee
 * yet to take a line manager from, so the subject says who it is. When it
 * names nobody usable (null, inactive, outside the company, or the employee
 * the request is about) the step falls back to the company's HR approvers,
 * like the other manager routes.
 */
interface NamesApprovalManager
{
    public function approvalManager(): ?User;
}
