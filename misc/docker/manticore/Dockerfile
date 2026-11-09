FROM manticoresearch/manticore:29.9.0@sha256:6e32ad69a4a1bf5e596b8b2abd00b7625fc2a146c3877b365a4d4840f5cffd12

COPY manticore.conf /etc/manticoresearch/manticore.conf
COPY crontab /etc/cron.d/manticore
COPY entrypoint.sh /usr/local/bin/manticore-entrypoint.sh

RUN chmod 0644 /etc/manticoresearch/manticore.conf /etc/cron.d/manticore \
    && chmod 0755 /usr/local/bin/manticore-entrypoint.sh \
    && mkdir -p /var/lib/manticore/data /var/log/manticore /var/run/manticore \
    && chown -R manticore:manticore /var/lib/manticore /var/log/manticore /var/run/manticore

VOLUME /var/lib/manticore

EXPOSE 9306 9312

ENTRYPOINT [ "/usr/local/bin/manticore-entrypoint.sh" ]
