import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/theme.dart';
import '../main.dart';
import 'shell.dart';

/// FR-10.3: the in-app inbox.
///
/// Push (FCM/APNs) is the primary channel, but it fails quietly whenever
/// the OS permission was denied or delivery is throttled. This screen is
/// the guarantee that a message sent by a teacher or admin is always
/// readable somewhere.
class NotificationsScreen extends StatelessWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l = AppState.instance.l;

    return Scaffold(
      appBar: AppBar(
        title: Text(l.t('notifications')),
        backgroundColor: DanaColors.brand,
        foregroundColor: Colors.white,
      ),
      body: AsyncView(
        load: () => Api.instance.get('/me/notifications'),
        builder: (context, data, reload) {
          final items = ((data['items'] as List?) ?? []).cast<Map<String, dynamic>>();

          if (items.isEmpty) {
            return Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.notifications_none, size: 40, color: DanaColors.textMuted),
                  const SizedBox(height: 12),
                  Text(l.t('no_notifications')),
                ],
              ),
            );
          }

          return RefreshIndicator(
            onRefresh: () async => reload(),
            child: ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: items.length,
              itemBuilder: (context, i) {
                final item = items[i];
                final unread = item['read'] != true;

                return Container(
                  margin: const EdgeInsets.only(bottom: 10),
                  decoration: BoxDecoration(
                    color: DanaColors.card,
                    borderRadius: BorderRadius.circular(DanaRadius.card),
                    border: Border.all(
                      color: unread ? DanaColors.brand : DanaColors.border,
                      width: 1.5,
                    ),
                  ),
                  child: ListTile(
                    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                    leading: Container(
                      width: 10,
                      height: 10,
                      margin: const EdgeInsets.only(top: 6),
                      decoration: BoxDecoration(
                        color: unread ? DanaColors.brand : Colors.transparent,
                        shape: BoxShape.circle,
                      ),
                    ),
                    title: Text(
                      // Server sends both languages; show the one that
                      // matches the interface setting (FR-4.14).
                      l.pick(item['title_tk'] as String?, item['title_ru'] as String?),
                      style: TextStyle(
                        fontSize: 15,
                        fontWeight: unread ? FontWeight.w700 : FontWeight.w600,
                      ),
                    ),
                    subtitle: Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text(
                        l.pick(item['body_tk'] as String?, item['body_ru'] as String?),
                        style: const TextStyle(fontSize: 13, height: 1.4),
                      ),
                    ),
                    onTap: unread
                        ? () async {
                            await Api.instance.post('/me/notifications/${item['id']}/read');
                            reload();
                          }
                        : null,
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}
