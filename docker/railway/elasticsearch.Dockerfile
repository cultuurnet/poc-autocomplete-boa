# Elasticsearch for Railway. Same version and the same no-auth single-node
# setup as docker-compose.yml; only reachable over Railway's private network,
# never give this service a public domain.
FROM docker.elastic.co/elasticsearch/elasticsearch:8.19.12

COPY docker/railway/elasticsearch.yml /usr/share/elasticsearch/config/elasticsearch.yml
COPY docker/railway/start-elasticsearch.sh /usr/local/bin/start-elasticsearch.sh

# Railway mounts volumes as root, and Elasticsearch refuses to run as root: the
# start script fixes the data directory's owner, then drops to uid 1000.
USER root

ENTRYPOINT ["/bin/tini", "--", "/usr/local/bin/start-elasticsearch.sh"]
# Setting ENTRYPOINT clears the base image's CMD, which is what tells
# docker-entrypoint.sh to start Elasticsearch rather than exec its arguments.
CMD ["eswrapper"]
