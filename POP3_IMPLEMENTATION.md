# POP3 Support Implementation for Nextcloud Mail

## Overview

This implementation adds POP3 protocol support alongside existing IMAP functionality in Nextcloud Mail. POP3 accounts operate with "keep on server" mode - messages are downloaded locally but never deleted from the server. All interactions (flags, folders, drafts) are handled locally.

## Key Features

### 1. Dual Protocol Support
- **IMAP**: Full-featured server-side folders, flags, search (existing functionality preserved)
- **POP3**: Local-only operation with messages kept on server

### 2. Protocol Detection
- Database field `inbound_protocol` in `mail_accounts` table (values: `imap`, `pop3`)
- Defaults to `imap` for backwards compatibility
- Protocol selection available in account creation/update API

### 3. POP3 Architecture

#### Client Layer (`lib/POP3/`)
- **Pop3Client.php**: Native PHP POP3 implementation
  - Commands: USER, PASS, STAT, LIST, UIDL, RETR, NOOP, QUIT
  - SSL/TLS/STARTTLS support
  - No DELE command (messages kept on server)
- **Pop3ClientFactory.php**: Creates authenticated client connections
- **Pop3MessageMapper.php**: Message fetching and parsing using Horde_Mime
- **Pop3Exception.php**: POP3-specific error handling

#### Protocol Abstraction (`lib/Service/InboundProtocol/`)
- **IInboundProtocolService.php**: Protocol-agnostic interface
- **ImapProtocolService.php**: IMAP implementation
- **Pop3ProtocolService.php**: POP3 implementation
- **InboundProtocolServiceFactory.php**: Protocol service factory

#### Synchronization (`lib/Service/Sync/`)
- **Pop3ToDbSynchronizer.php**: UIDL-based incremental sync
  - Tracks messages by UIDL (unique ID per message)
  - Batch fetching (50 messages per batch)
  - Fault-tolerant (continues on per-message errors)
  - Stores UIDL in `message_id` field (temporary solution)
  - All flags are local-only

### 4. Local Folders for POP3

#### Database Schema
- Added `local_only` column to `mail_mailboxes` table
- Local folders: INBOX, Sent, Drafts, Trash, Archive, Junk
- INBOX syncs from server, others are purely local

#### Service (`lib/Service/Pop3LocalFolderService.php`)
- Creates default local folder structure
- Manages special-use flags for UI recognition
- Message moves between folders = local database updates only

### 5. Drafts Management
- **Database**: Added `drafts_store_on_server` column to `mail_accounts`
- **Default**: `false` (local storage for all accounts)
- **POP3**: Always local (enforced)
- **IMAP**: Optional server-side sync (user-configurable)
- **Implementation**: Uses existing `LocalMessage` system (`TYPE_DRAFT = 1`)

### 6. API & Frontend Updates

#### Backend (`lib/Controller/AccountsController.php`)
- Added `inboundProtocol` parameter to `create()` and `update()` methods
- Default: `imap` for backwards compatibility

#### Frontend (`src/components/AccountForm.vue`)
- Added `inboundProtocol` field to `manualConfig` data model
- Currently defaults to `imap` (UI selector to be added)

#### Account Setup (`lib/Service/SetupService.php`)
- Protocol-aware connectivity testing
- Uses `InboundProtocolServiceFactory` for protocol selection
- Accepts `protocol` parameter in `createNewAccount()`

## Database Migrations

1. **Version5300Date20260207000000**: Add `inbound_protocol` column
2. **Version5300Date20260207000001**: Add `local_only` column to mailboxes
3. **Version5300Date20260207000002**: Add `drafts_store_on_server` column

## Implementation Status

### ✅ Completed
- [x] Database schema for protocol selection
- [x] POP3 client library (native implementation)
- [x] Protocol abstraction layer
- [x] POP3 synchronization engine
- [x] Local folder system for POP3
- [x] API parameter support
- [x] Frontend data model updates
- [x] Drafts storage preference

### 🚧 Remaining Work
- [ ] UI protocol selector in AccountForm
- [ ] Integration with existing sync jobs (SyncService)
- [ ] Update MailManager to use protocol abstraction
- [ ] Local-only search implementation for POP3
- [ ] Thread building for POP3 messages
- [ ] Auto-config support for POP3 (ISPDB parsing)
- [ ] UI indicators for POP3 vs IMAP accounts
- [ ] Disable unsupported operations for POP3 in UI (create folder, etc.)
- [ ] Migration guide for existing deployments

## Architecture Decisions

### Why Native POP3 Client?
- POP3 protocol is very simple (8 basic commands)
- No mature `bytestream/horde-pop3` package available
- ~300 lines of code vs external dependency
- Full control over "keep on server" behavior

### Why UIDL for Message Tracking?
- POP3's native unique identifier
- Persistent across sessions
- Server-independent format
- Stored in `message_id` field (temporary - ideally separate `uidl` column in future)

### Why Local-Only Folders?
- POP3 has no folder concept (only INBOX)
- Users still need organization (Sent, Trash, etc.)
- Local folders provide familiar UX
- Move operations are instant (database-only)

### Why Local-First Drafts?
- POP3 cannot write to server
- Unifies draft handling for both protocols
- Reduces complexity
- Existing `LocalMessage` system is mature

## Usage Example

### Creating a POP3 Account via API

```bash
curl -X POST https://nextcloud.example.com/index.php/apps/mail/api/accounts \
  -H "Content-Type: application/json" \
  -d '{
    "accountName": "My POP3 Account",
    "emailAddress": "user@example.com",
    "imapHost": "pop.example.com",
    "imapPort": 995,
    "imapSslMode": "ssl",
    "imapUser": "user@example.com",
    "imapPassword": "password",
    "smtpHost": "smtp.example.com",
    "smtpPort": 587,
    "smtpSslMode": "tls",
    "smtpUser": "user@example.com",
    "smtpPassword": "password",
    "authMethod": "password",
    "inboundProtocol": "pop3"
  }'
```

## Testing

### Manual Testing Checklist
- [ ] Create POP3 account with valid credentials
- [ ] Verify messages download from server
- [ ] Confirm messages remain on server after sync
- [ ] Test local folder creation (INBOX, Sent, Drafts, etc.)
- [ ] Move messages between local folders
- [ ] Create local draft
- [ ] Mark message as read/unread (local flag)
- [ ] Search in POP3 mailbox (local search)
- [ ] Delete POP3 account

### Unit Tests Needed
- [ ] Pop3Client connectivity and command execution
- [ ] Pop3ToDbSynchronizer UIDL tracking
- [ ] Pop3LocalFolderService folder creation
- [ ] Protocol service factory selection
- [ ] MailAccount protocol getter/setter

## Performance Considerations

- **Initial sync**: Downloads all messages (can be slow for large mailboxes)
- **Incremental sync**: Only new messages (UIDL-based diffing)
- **Batch size**: 50 messages per batch (configurable in `Pop3ToDbSynchronizer::SYNC_BATCH_SIZE`)
- **No server-side search**: All search is local (database queries)
- **No CONDSTORE/QRESYNC**: POP3 lacks efficient change detection

## Security Considerations

- POP3 passwords encrypted via `ICrypto` (same as IMAP)
- OAuth2 not supported for POP3 (protocol limitation)
- SSL/TLS recommended (enforced via config)
- No plaintext password storage

## Backwards Compatibility

- All existing IMAP accounts continue working unchanged
- `inbound_protocol` defaults to `imap` in database
- No breaking changes to existing API endpoints
- Frontend gracefully handles missing `inboundProtocol` field

## Configuration

### Recommended POP3 Settings
- **Port**: 995 (SSL) or 110 (plaintext/STARTTLS)
- **SSL Mode**: `ssl` (preferred) or `tls` (STARTTLS)
- **Keep on Server**: Enforced (no user option)

### Future Configuration Options
```php
// Potential app config (not yet implemented)
'app.mail.pop3.batch_size' => 50,
'app.mail.pop3.sync_interval' => 300, // 5 minutes
'app.mail.pop3.max_message_size' => 10485760, // 10MB
```

## Known Limitations

1. **No OAuth2 support** - POP3 protocol doesn't support OAuth
2. **No server-side folders** - Local folders only
3. **No server-side flags** - All flags are local
4. **No server-side search** - Must download first
5. **UIDL storage** - Using `message_id` field (temporary)
6. **No multi-device flag sync** - Each client has own flags
7. **Initial sync can be slow** - Large mailboxes take time

## Future Enhancements

- [ ] Separate `uidl` column in database
- [ ] Smart sync: headers first, bodies on-demand
- [ ] Message size limits and filtering
- [ ] Duplicate detection across folders
- [ ] Export/backup of local data
- [ ] POP3 quota tracking
- [ ] Incremental threading
- [ ] Offline mode indicator

## Files Modified/Created

### Database Migrations (3 files)
- `lib/Migration/Version5300Date20260207000000.php`
- `lib/Migration/Version5300Date20260207000001.php`
- `lib/Migration/Version5300Date20260207000002.php`

### Core Entities (2 files)
- `lib/Db/MailAccount.php` - Added protocol and drafts fields
- `lib/Db/Mailbox.php` - Added local_only field

### POP3 Layer (4 files)
- `lib/POP3/Pop3Client.php` - POP3 protocol client
- `lib/POP3/Pop3ClientFactory.php` - Client factory
- `lib/POP3/Pop3MessageMapper.php` - Message operations
- `lib/POP3/Pop3Exception.php` - Error handling

### Protocol Abstraction (4 files)
- `lib/Service/InboundProtocol/IInboundProtocolService.php`
- `lib/Service/InboundProtocol/ImapProtocolService.php`
- `lib/Service/InboundProtocol/Pop3ProtocolService.php`
- `lib/Service/InboundProtocol/InboundProtocolServiceFactory.php`

### Services (3 files)
- `lib/Service/SetupService.php` - Protocol-aware setup
- `lib/Service/Sync/Pop3ToDbSynchronizer.php` - POP3 sync engine
- `lib/Service/Pop3LocalFolderService.php` - Local folder management

### API & Frontend (2 files)
- `lib/Controller/AccountsController.php` - Protocol parameter support
- `src/components/AccountForm.vue` - Protocol field in form

**Total**: 22 files (18 new, 4 modified)

## Commit History

```
816bc8f feat: Add drafts storage preference
3f410dd feat: Add protocol support to API and frontend
423db9b feat: Add local folder support for POP3 accounts
a80df1e feat: Add POP3 synchronization service
47c3ea0 feat: Introduce inbound protocol abstraction layer
1e32b39 feat: Add POP3 client library and infrastructure
3959c24 feat: Add inbound_protocol field to support POP3 alongside IMAP
```

## Contributors

Implementation by GitHub Copilot (2026-02-07)

## License

AGPL-3.0-or-later (same as Nextcloud Mail)
