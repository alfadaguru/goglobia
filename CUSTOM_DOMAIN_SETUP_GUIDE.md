# WHITE-LABEL CUSTOM DOMAIN SETUP GUIDE

## Two Ways to Host White-Label Bookings

Your suppliers can use EITHER the platform's branded domain OR their own custom domain.

### OPTION 1: Branded Domain (Default - No Setup Needed)

**What it is**: `bizarreluxuryapartments.yourdomain.com` (or whatever you configured)

**Who sets it up**: Already done by you in Admin Settings
- Admin Settings → Supplier branded domain root
- Example: Set to `yourdomain.com`
- System auto-generates: `{property-slug}.yourdomain.com`

**Guest sees**: White-label site on branded subdomain
**Technical**: DNS resolves to your server, routed by Host header

---

### OPTION 2: Custom Domain (Supplier's Own Domain)

**What it is**: Supplier's custom domain like `bookings.bizarreluxuryapartments.com`

**Who sets it up**: The SUPPLIER in their dashboard
- Supplier Dashboard → Stays → Property → Site & Domain
- Enter custom domain: `bookings.bizarreluxuryapartments.com`
- System provides CNAME target

**Steps for Supplier to Add Custom Domain**:

1. **Get the CNAME target from system**
   - Supplier goes to Site & Domain page
   - System shows: "Point your domain to: `sites.yourdomain.com`"

2. **Update DNS at their registrar**
   - Registrar: GoDaddy, Namecheap, CloudFlare, etc.
   - Create CNAME record:
     ```
     Name: bookings
     Type: CNAME
     Value: sites.yourdomain.com
     TTL: 3600 (or default)
     ```
   - Result: `bookings.bizarreluxuryapartments.com` → `sites.yourdomain.com`

3. **Verify in system**
   - System checks: Can we reach DNS for their domain?
   - Status: `pending` (DNS found) → `verified` (working) → `active` (TLS cert issued)

4. **Site goes live**
   - Once status = `verified`, guests can visit their custom domain
   - White-label booking page serves from their custom domain

---

## How the System Routes Requests

```
Guest visits https://bookings.bizarreluxuryapartments.com
                           ↓
        DNS resolves to sites.yourdomain.com
                           ↓
        Request hits your server
                           ↓
        Your server checks Host header
                           ↓
        Looks up in stays_site table:
            - custom_domain = 'bookings.bizarreluxuryapartments.com'
            - OR hostname = 'bizarreluxuryapartments.yourdomain.com'
                           ↓
        Finds stay_id, loads property's white-label site
                           ↓
        Guest sees branded booking page
```

---

## Admin Configuration Required

You've already done this, but for reference:

**Admin Settings**:
```
Supplier branded domain root: yourdomain.com
Supplier site IP: (your server IP, optional for load balancing)
```

This enables:
- Auto-generation of branded subdomains
- CNAME target for custom domains

---

## What Suppliers See

### Before Setup
- Red warning: "A branded address isn't configured yet. Administrator needs to set supplier domain in Settings"

### After Admin Setup (You Just Did This!)
- Branded address showing: `bizarreluxuryapartments.yourdomain.com`
- Live public link: `https://yourdomain.com/book/bizarreluxuryapartments` (always works)
- Option to add custom domain

### Custom Domain Section
```
Branded address: bizarreluxuryapartments.yourdomain.com ✓ Active

Custom domain (optional):
  Domain: [_________ enter domain ___________]
  Status: Not configured
  
  When you add a custom domain:
  1. Enter your domain (e.g., bookings.hotel.com)
  2. Add CNAME to your DNS: sites.yourdomain.com
  3. We verify DNS is working
  4. Your site goes live on your custom domain
```

---

## Troubleshooting

**"Branded address isn't configured yet"**
- You need to set supplier_site_domain in Admin Settings
- You JUST did this - cache is cleared now
- Reload the page

**Custom domain shows "pending" forever**
- CNAME not set in their DNS registrar
- Have them verify: `nslookup bookings.hotel.com`
- Should resolve to `sites.yourdomain.com`

**Custom domain shows "verified" but not working**
- DNS works, TLS cert is being issued
- Wait 10-15 minutes for certificate provisioning
- Reload after TLS is active (status becomes "active")

**Requests to custom domain timeout**
- Check your server can listen on all Host headers
- Verify firewall allows inbound on port 443 for custom domain
- Check supplier_site_ip setting in Admin

---

## Security Notes

- Custom domains are verified via DNS lookup (CNAME check)
- TLS certificates are auto-provisioned via Let's Encrypt
- Only "verified" or "active" custom domains serve content
- "pending" or "none" states reject traffic (404 only)
- Supplier's DNS validation prevents domain hijacking

---

## Next: White-Label Booking System Integration

Once suppliers have their domains set up:
1. They create a property in the system
2. Property gets branded white-label booking page
3. Guests book without login (guest email + phone only)
4. Payment via Paystack/Stripe/Adyen
5. Revenue split automatic
6. Supplier payout automatic

See: README_INTEGRATION_COMPLETE.md

