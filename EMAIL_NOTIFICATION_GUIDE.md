# Email Notification & Approval System - Setup Guide

## Overview
The email notification system has been completely redesigned to match your portfolio's modern dark UI theme and now includes an automated approval workflow.

## What's New

### 1. **Modern UI-Matching Email Design**
- Dark theme with cyan (#00fff7) accents matching your portfolio
- Professional gradient backgrounds
- Responsive design for all devices
- Clean, modern typography using Inter font

### 2. **Three-Email Workflow**

#### Email 1: Admin Notification (When Client Submits Payment)
- **Sent to:** janrusselpenafiel01172005@gmail.com
- **Contains:**
  - Payment ID for tracking
  - Client details (name, email)
  - Payment amount and GCash reference number
  - **APPROVE BUTTON** - One-click approval
  - Submission timestamp

#### Email 2: Client Confirmation (Immediate)
- **Sent to:** Client's email
- **Contains:**
  - Payment ID
  - "Under Review" status badge
  - Payment details
  - What happens next information
  - Expected timeline (1-24 hours)

#### Email 3: Client Approval (When You Click Approve)
- **Sent to:** Client's email
- **Contains:**
  - Payment ID
  - "Approved" status badge (green)
  - Payment details
  - Thank you message
  - Next steps information
  - Contact information

### 3. **Secure Approval System**
- HMAC-SHA256 token-based security
- Each payment gets a unique Payment ID
- Approval links are tamper-proof
- Payment data cached for retrieval

## Files Created/Modified

### New Files:
1. **gcash_approval.php** - Handles approval button clicks
   - Verifies security tokens
   - Sends approval email to client
   - Displays success/error pages
   - Updates payment status in cache

### Modified Files:
1. **gcash_notification.php** - Enhanced with:
   - New UI-matching email templates
   - Payment ID generation
   - Client confirmation email
   - Cache storage for payment data
   - Approval token generation

2. **.env** - Added:
   - `APPROVAL_SECRET` - Security key for approval tokens

## How It Works

### Step-by-Step Flow:

```
Client Submits Payment
        ↓
gcash_notification.php processes submission
        ↓
Generates unique Payment ID (e.g., PAY-1234567890-ABC12345)
        ↓
Saves payment data to cache/
        ↓
┌─────────────────────────────────────┐
│ TWO EMAILS SENT SIMULTANEOUSLY:     │
│                                     │
│ 1. Admin Email (You)                │
│    - Payment details                │
│    - APPROVE BUTTON                 │
│                                     │
│ 2. Client Email                     │
│    - "Under Review" status          │
│    - Payment confirmation           │
└─────────────────────────────────────┘
        ↓
You receive admin email
        ↓
You verify payment in GCash app
        ↓
You click APPROVE button in email
        ↓
gcash_approval.php processes approval
        ↓
Verifies security token
        ↓
Sends approval email to client
        ↓
Updates payment status to "approved"
        ↓
Shows success page to you
```

## Testing the System

### 1. Test Payment Submission
```bash
curl -X POST http://localhost/doc/gcash_notification.php \
  -H "Content-Type: application/json" \
  -d '{
    "clientName": "Test Client",
    "clientEmail": "test@example.com",
    "amount": 5000.00,
    "gcashReferenceNumber": "1001543610110"
  }'
```

Expected response:
```json
{
  "ok": true,
  "message": "Payment details submitted and notification sent successfully.",
  "paymentId": "PAY-1234567890-ABC12345"
}
```

### 2. Check Emails
You should receive:
1. **Admin email** at janrusselpenafiel01172005@gmail.com with APPROVE button
2. **Client email** at test@example.com with "Under Review" status

### 3. Test Approval
Click the APPROVE button in the admin email. This will:
1. Open gcash_approval.php in browser
2. Verify the token
3. Send approval email to client
4. Show success page

## Email Design Features

### Admin Email
- **Header:** Cyan gradient with payment icon
- **Payment ID Box:** Prominent display with monospace font
- **Reference Number:** Highlighted box with large text
- **Details Grid:** Clean rows with icons
- **Action Section:** 
  - Cyan bordered box
  - Large green APPROVE button
  - Hover effects
  - Clear instructions

### Client Confirmation Email
- **Header:** Orange/yellow gradient (warning color for "pending")
- **Status Badge:** "UNDER REVIEW" in orange
- **Info Box:** What happens next with bullet points
- **Timeline:** Clear expectations

### Client Approval Email
- **Header:** Green gradient (success color)
- **Status Badge:** "APPROVED" in green
- **Message Box:** Thank you message
- **Next Steps:** Project progression details
- **Contact Info:** Easy access to support

## Security Features

### 1. Token-Based Approval
- Each approval link contains HMAC-SHA256 token
- Token is generated using payment ID + secret key
- Tamper-proof: any modification invalidates the token
- Uses `hash_equals()` for timing-attack safe comparison

### 2. Payment Data Storage
- Payment data cached as JSON files
- Filename is MD5 hash of payment ID
- Contains all necessary details for approval
- Status tracking (pending → approved)

### 3. Secret Key Configuration
```env
APPROVAL_SECRET=portfolio-approval-secret-2024-change-me
```
**IMPORTANT:** Change this to a random string in production!

## Customization

### Change Email Colors
Edit the CSS in these functions:
- `generateAdminEmailBody()` - Admin notification
- `generateClientConfirmationEmail()` - Client pending email
- `generateApprovalEmailBody()` - Client approval email

Key colors:
- Cyan accent: `#00fff7`
- Success green: `#28a745`
- Warning orange: `#ffc107`
- Background: `#181825` to `#1a1c2e`

### Change Approval Secret
In `.env` file:
```env
APPROVAL_SECRET=your-new-random-secret-key-here
```

### Modify Email Content
Edit the HTML templates in the respective functions. All templates use HEREDOC syntax for easy editing.

## Troubleshooting

### Emails Not Sending
1. Check SMTP credentials in `.env`
2. Verify PHPMailer is installed: `vendor/autoload.php` exists
3. Check error logs: `C:\xampp\php\logs\php_error_log`
4. Test with `test_phpmailer.php`

### Approval Link Not Working
1. Verify `APPROVAL_SECRET` is set in `.env`
2. Check if cache file exists: `cache/[md5].json`
3. Ensure `gcash_approval.php` is accessible
4. Check URL is complete (not truncated in email)

### Cache Files Not Created
1. Check `cache/` directory permissions
2. Verify directory exists and is writable
3. Check PHP error logs for permission errors

### Token Validation Fails
1. Ensure approval link is not modified
2. Check `APPROVAL_SECRET` hasn't changed
3. Verify payment data exists in cache
4. Look for URL encoding issues

## File Structure

```
doc/
├── gcash_notification.php      # Main notification handler
├── gcash_approval.php          # Approval endpoint
├── .env                        # Configuration (includes APPROVAL_SECRET)
├── cache/                      # Payment data storage
│   ├── [md5hash].json         # Individual payment records
│   └── ...
└── vendor/
    └── autoload.php           # PHPMailer autoloader
```

## Payment Data Cache Format

Each payment creates a JSON file in `cache/`:
```json
{
  "paymentId": "PAY-1234567890-ABC12345",
  "clientName": "Juan Dela Cruz",
  "clientEmail": "juan@example.com",
  "amount": 5000.00,
  "gcashReferenceNumber": "1001543610110",
  "submittedAt": "2024-03-19 10:30:00",
  "status": "pending"
}
```

After approval:
```json
{
  "paymentId": "PAY-1234567890-ABC12345",
  "clientName": "Juan Dela Cruz",
  "clientEmail": "juan@example.com",
  "amount": 5000.00,
  "gcashReferenceNumber": "1001543610110",
  "submittedAt": "2024-03-19 10:30:00",
  "status": "approved",
  "approvedAt": "2024-03-19 11:45:00"
}
```

## Future Enhancements (Optional)

1. **Database Integration**
   - Replace cache files with database storage
   - Better scalability and querying

2. **Admin Dashboard**
   - View all payments
   - Bulk approval
   - Payment statistics

3. **Email Templates**
   - Move HTML to separate template files
   - Easier customization

4. **Webhook Integration**
   - Automatic payment verification via PayMongo
   - Real-time status updates

5. **SMS Notifications**
   - Twilio integration
   - Instant client alerts

## Support

If you encounter any issues:
1. Check error logs in `C:\xampp\php\logs\php_error_log`
2. Verify all files are in place
3. Test SMTP with `test_phpmailer.php`
4. Ensure `.env` is properly configured

---

**Last Updated:** 2024-03-19  
**Version:** 2.0 - Complete UI Redesign + Approval System
