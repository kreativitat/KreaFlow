# Security policy

Report suspected vulnerabilities privately to mail@kreativitat.com. Include a minimal synthetic reproduction and affected version; do not include credentials, customer documents, employee details or production database captures in a public issue.

Keep Dolibarr, PHP, module dependencies and configured integrations within their supported security updates. Grant native permissions and integration tokens only to the intended company and operation. Store secrets outside source control and outside the web root.

If a secret is discovered, revoke or rotate it with the issuer first, investigate its use, then remove it from affected Git history and cached/distributed copies as appropriate. Do not paste the value into issues, commit messages, logs or scan artifacts. Contact the repository owner privately for coordinated handling.
