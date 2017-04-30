# coding: utf8
from xml.sax.saxutils import XMLGenerator
from datetime import datetime, timedelta
import collections
import itertools
import psycopg2.extras
import psycopg2.pool
import pytz
import re
import time
import tzlocal
import webob
import webob.exc
import yaml

with open("config", "r") as f:
	config = yaml.safe_load(f)

tz = tzlocal.get_localzone()
date_re = re.compile(r"[0-9]*")

max_pool = 20

pool = psycopg2.pool.ThreadedConnectionPool(5, max_pool, config["dsn"], connection_factory=psycopg2.extras.RealDictConnection)

def getconn(pool, max_conns):
        attempts = max_conns + 1
        conn = None
        while attempts > 0:
                conn = pool.getconn()
                try:
                        conn.isolation_level
                        return conn
                except psycopg2.OperationalError:
                        pool.putconn(conn, close=True)
                        attempts -= 1
        return conn

Period = collections.namedtuple("Period", ["start_name", "end_name", "short_name", "start_ts", "end_ts", "compare_start_ts", "compare_end_ts", "uri"])

class View:
	def __init__(self, req):
		uri = req.environ["REQUEST_URI"].split("?")[0]
		uri = uri[1:].split("/")
		if len(uri) == 1:
			self.date = uri[0]
		else:
			raise webob.exc.HTTPNotFound("Invalid URI")

		if len(self.date) not in [0, 4, 6, 8, 10] or not date_re.match(self.date):
			raise webob.exc.HTTPNotFound("Invalid date format")

		if not self.date:
			self.date = "{0:04d}".format(datetime.now().year)

		self.periods = []
		self.parent = {}
		base_uri = "/" + self.device + "/" if len(uri) >= 2 else "/"

		if len(self.date) == 4:
			self.period_type = "Month"
			year = int(self.date)
			self.name = "{0:04d}".format(year)
			for month in range(1, 13):
				local_start = tz.localize(datetime(year, month, 1))
				local_end = tz.localize(datetime(year if month < 12 else year + 1, month + 1 if month < 12 else 1, 1))
				local_compare_start = tz.localize(datetime(year - 1, month, 1))
				local_compare_end = tz.localize(datetime(year - 1 if month < 12 else year, month + 1 if month < 12 else 1, 1))
				self.periods.append(Period(local_start.strftime("%B"), None, local_start.strftime("%b"), local_start, local_end, local_compare_start, local_compare_end, "{0}{1:04d}{2:02d}".format(base_uri, year, local_start.month)))
		elif len(self.date) == 6:
			self.period_type = "Day"
			year = int(self.date[0:4])
			month = int(self.date[4:6])
			self.name = "{0:04d}-{1:02d}".format(year, month)
			self.parent = { "name": "{0:04d}".format(year), "uri": "{0}{1:04d}".format(base_uri, year) }
			start = datetime(year, month, 1)
			end = datetime(year if month < 12 else year + 1, month + 1 if month < 12 else 1, 1)
			while start < end:
				local_start = tz.localize(start)
				local_end = tz.localize(start + timedelta(days=1))
				local_compare_start = tz.localize(start + timedelta(weeks=-52))
				local_compare_end = tz.localize(start + timedelta(weeks=-52, days=1))
				self.periods.append(Period(local_start.strftime("%d"), None, None, local_start, local_end, local_compare_start, local_compare_end, "{0}{1:04d}{2:02d}{3:02d}".format(base_uri, year, month, local_start.day)))
				start += timedelta(days=1)
		elif len(self.date) == 8:
			self.period_type = "Hour"
			year = int(self.date[0:4])
			month = int(self.date[4:6])
			day = int(self.date[6:8])
			self.name = "{0:04d}-{1:02d}-{2:02d}".format(year, month, day)
			self.parent = { "name": "{0:04d}-{1:02d}".format(year, month), "uri": "{0}{1:04d}{2:02d}".format(base_uri, year, month) }
			start = tz.localize(datetime(year, month, day)).astimezone(pytz.utc)
			end = datetime(year, month, day) + timedelta(days=1)
			while start.astimezone(tz).replace(tzinfo=None) < end:
				local_start = start.astimezone(tz)
				local_end = (start + timedelta(hours=1)).astimezone(tz)
				local_compare_start = (start + timedelta(weeks=-52)).astimezone(tz)
				local_compare_end = (start + timedelta(weeks=-52, hours=1)).astimezone(tz)
				self.periods.append(Period(local_start.strftime("%H:%M"), (local_start + timedelta(hours=1, milliseconds=-1)).strftime("%H:%M"), local_start.strftime("%H"), local_start, local_end, local_compare_start, local_compare_end, "{0}{1:04d}{2:02d}{3:02d}{4:02d}".format(base_uri, year, month, day, local_start.hour)))
				start += timedelta(hours=1)
		elif len(self.date) == 10:
			self.period_type = "Minute"
			year = int(self.date[0:4])
			month = int(self.date[4:6])
			day = int(self.date[6:8])
			hour = int(self.date[8:10])
			self.name = "{0:04d}-{1:02d}-{2:02d} {3:02d}".format(year, month, day, hour)
			self.parent = { "name": "{0:04d}-{1:02d}-{2:02d}".format(year, month, day), "uri": "{0}{1:04d}{2:02d}{3:02d}".format(base_uri, year, month, day) }
			start = tz.localize(datetime(year, month, day, hour)).astimezone(pytz.utc)
			end = datetime(year, month, day, hour) + timedelta(hours=1)
			while start.astimezone(tz).replace(tzinfo=None) < end:
				local_start = start.astimezone(tz)
				local_end = (start + timedelta(minutes=1)).astimezone(tz)
				self.periods.append(Period(local_start.strftime("%M:%S"), (local_start + timedelta(minutes=1, milliseconds=-1)).strftime("%M:%S"), local_start.strftime("%M"), local_start, local_end, None, None, None))
				start += timedelta(minutes=1)

class Usage:
	def __init__(self, view):
		self.view = view
		db = getconn(pool, max_pool)
		try:
			c = db.cursor()

			start_periods = [period.start_ts for period in view.periods]
			end_periods = [period.end_ts for period in view.periods]
			compare_start_periods = [period.compare_start_ts for period in view.periods]
			compare_end_periods = [period.compare_end_ts for period in view.periods]
			now = time.time()
			if config["type"] == "gas":
				c.execute("SELECT"
					+ " period.start"
					+ ", meters.id"
					+ ", reading_calculate(meters.id, period.stop) - reading_calculate(meters.id, period.start) AS usage"
					+ ", reading_calculate(meters.id, period.compare_stop) - reading_calculate(meters.id, period.compare_start) AS compare_usage"
					+ " FROM unnest(%(start)s, %(stop)s, %(compare_start)s::timestamptz[], %(compare_stop)s::timestamptz[]) AS period(start, stop, compare_start, compare_stop)"
					+ ", unnest(%(meters)s) AS meters(id)"
					+ " ORDER BY period.start, meters.id",
					{
						"meters": config["meters"],
						"start": start_periods, "stop": end_periods,
						"compare_start": compare_start_periods, "compare_stop": compare_end_periods
					})
			elif config["type"] == "electricity":
				c.execute("SELECT"
					+ " period.start"
					+ ", meters.id"
					+ ", meter_reading_usage_rescale(meters.base_id, meters.id, period.start, period.stop) AS usage"
					+ ", meter_reading_usage_rescale(meters.base_id, meters.id, period.compare_start, period.compare_stop) AS compare_usage"
					+ " FROM unnest(%(start)s, %(stop)s, %(compare_start)s::timestamptz[], %(compare_stop)s::timestamptz[]) AS period(start, stop, compare_start, compare_stop)"
					+ ", unnest(%(meters)s, %(base_meters)s) AS meters(id, base_id)"
					+ " ORDER BY period.start, meters.id",
					{	"meters": config["meters"].keys(), "base_meters": config["meters"].values(),
						"start": start_periods, "stop": end_periods,
						"compare_start": compare_start_periods, "compare_stop": compare_end_periods
					})
			self.query_time = time.time() - now
			self.usage = c.fetchall()

			c.close()
			db.commit()
		finally:
			pool.putconn(db)

	def output(self, doc):
		doc.startElement("usage", { "name": self.view.name })
		if self.view.parent:
			doc.startElement("parent", { "name": self.view.parent["name"], "uri": self.view.parent["uri"] })
			doc.endElement("parent")

		doc.startElement("periods", { "type": self.view.period_type, "query_time": str(self.query_time) })
		pos = 0
		for period in self.view.periods:
			attrs = { "name": u"–".join(filter(None, [period.start_name, period.end_name])) }
			if period.short_name:
				attrs["short_name"] = period.short_name
			if period.uri:
				attrs["uri"] = period.uri
#			attrs["from"] = period.start_ts.isoformat()
#			attrs["to"] = period.end_ts.isoformat()

			usage = 0
			compare_usage = 0
			while len(self.usage) > pos and self.usage[pos]["start"] == period.start_ts:
				if self.usage[pos]["usage"] is not None:
					usage += self.usage[pos]["usage"]

				if self.usage[pos]["compare_usage"] is not None:
					compare_usage += self.usage[pos]["compare_usage"]

				pos += 1

			if usage:
				attrs["usage"] = str(usage)
			if compare_usage:
				attrs["compare_usage"] = str(compare_usage)

			doc.startElement("period", attrs)
			doc.endElement("period")

		doc.endElement("periods")
		doc.endElement("usage")

def application(environ, start_response):
	try:
		attrs = {}
		if config["type"] == "gas":
			attrs["units"] = u"m³"
		elif config["type"] == "electricity":
			attrs["units"] = u"kW·h"
		else:
			raise webob.exc.HTTPInternalServerError("Unknown type configured")

		req = webob.Request(environ)
		res = webob.Response(content_type="application/xml")
		usage = Usage(View(req))

		f = res.body_file
		doc = XMLGenerator(f, "UTF-8")
		doc.startDocument()
		f.write('<?xml-stylesheet type="text/xsl" href="/usage.xsl"?>\n'.encode("UTF-8"))
		doc.startElement(config["type"], attrs)
		usage.output(doc)
		doc.endElement(config["type"])

		return res(environ, start_response)
	except webob.exc.HTTPException, e:
		return e(environ, start_response)
