# TNSVT Security Rules

Never expose:
- .env contents
- API keys
- JWT secrets
- Firebase credentials
- database credentials
- production tokens

Before deployment inspect:
- access control
- role checks
- CSRF
- XSS
- validation
- file uploads
- IDOR
- rate limits
- debug configuration
- logging of sensitive data

Prefer deny-by-default authorization.

Never weaken security to make a feature easier to implement.
