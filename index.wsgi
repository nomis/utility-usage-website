# Copyright 2011-2012,2015-2017,2021-2023  Simon Arlott
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.

from datetime import datetime, timedelta
from xml.sax.saxutils import XMLGenerator
import collections
import itertools
import os
import psycopg2.extras
import psycopg2.pool
import pytz
import re
import rrdtool
import tempfile
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
			self.output = "table"
		elif len(uri) == 3 and uri[1] == "graph" and uri[2] in ["load", "supply"]:
			self.date = uri[0]
			self.output = "graph"
			self.graph = uri[2]
		else:
			raise webob.exc.HTTPNotFound("Invalid URI")

		if len(self.date) not in [0, 4, 6, 8, 10] or not date_re.match(self.date):
			raise webob.exc.HTTPNotFound("Invalid date format")

		if not self.date:
			self.date = "{0:04d}".format(datetime.now().year)

		self.periods = []
		self.parent = {}
		base_uri = "/"

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
					{	"meters": list(config["meters"].keys()), "base_meters": [x["base"] for x in config["meters"].values()],
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
			attrs = { "name": "–".join(filter(None, [period.start_name, period.end_name])) }
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

class Graph:
	def __init__(self, view):
		self.view = view
		self.data = b""

		load_rrds = [os.path.join(config["rrd"], meter["rrd"] + ",load.rrd") for meter in config["meters"].values()]
		supply_rrds = [os.path.join(config["rrd"], meter["rrd"] + ",supply.rrd") for meter in config["meters"].values()]

		start = int((view.periods[0].start_ts - datetime(1970, 1, 1, tzinfo=pytz.utc)).total_seconds())
		end = int((view.periods[-1].end_ts - datetime(1970, 1, 1, tzinfo=pytz.utc)).total_seconds())

		command = [
			"-s", str(start),
			"-e", str(end),

			"-a", "PNG",
			"-w", "1103",
			"-h", "200",
			"-R", "mono",
			"-G", "mono",
			"--border", "0",
			"-c", "BACK#FFFFFF",

			"-A",
		]

		if view.graph == "load":
			command.extend([
				"-v", "W",
			])

			cfs = ["MIN", "AVERAGE", "MAX"]
			for ds in ["current", "activePower", "reactivePower", "apparentPower"]:
				cdef = dict({(cf, "") for cf in cfs})
				cdef2 = dict({(cf, "") for cf in cfs})
				for i, rrd in enumerate(load_rrds):
					for cf in cfs:
						step = ":step=86400" if view.period_type == "Month" else ""
						command.append("DEF:{0}{1}_{2}={3}:{0}:{2}{4}".format(ds, i, cf, load_rrds[i], step))
						step = 86400 if view.period_type == "Month" else 3600 * 4
						command.append("DEF:{0}{1}_step_{2}={3}:{0}:{2}:step={4}".format(ds, i, cf, load_rrds[i], step))
						if i == 0:
							cdef[cf] = "CDEF:{0}_{2}={0}{1}_{2}".format(ds, i, cf)
							cdef2[cf] = "CDEF:{0}_step_{2}={0}{1}_step_{2}".format(ds, i, cf)
						else:
							cdef[cf] += ",{0}{1}_step_{2},+".format(ds, i, cf)
							cdef2[cf] += ",{0}{1}_step_{2},+".format(ds, i, cf)
				command.extend(cdef.values())
				command.extend(cdef2.values())

			for cf in cfs:
				command.append("CDEF:reactivePower_{0}_neg=0,reactivePower_{0},-".format(cf))

			command.extend([
				"AREA:activePower_AVERAGE#FF9900:Active Power",
				"AREA:reactivePower_AVERAGE_neg#CC00CC:Reactive Power",
			])

			if view.period_type in ("Month", "Day"):
				command.append("CDEF:activePower_step_MIN_trend=activePower_step_MIN")

				command.extend([
					"LINE1:activePower_step_MIN_trend#000000",
				])
			elif view.period_type == "Hour":
				command.extend([
					"LINE1:activePower_MAX#000000",
					"LINE1:reactivePower_MAX_neg#000000",
				])
		elif view.graph == "supply":
			if view.period_type == "Minute":
				command.append("-Y")

			command.extend([
				"-v", "V",
			])

			cfs = ["MIN", "AVERAGE", "MAX"]
			for ds in ["voltage", "frequency", "temperature"]:
				cdef = dict({(cf, "") for cf in cfs})
				for i, rrd in enumerate(supply_rrds):
					for cf in cfs:
						step = ":step=86400" if view.period_type == "Month" else ""
						command.append("DEF:{0}{1}_{2}={3}:{0}:{2}{4}".format(ds, i, cf, supply_rrds[i], step))
						if i == 0:
							cdef[cf] = "CDEF:{0}_{2}={0}{1}_{2}".format(ds, i, cf)
						else:
							cdef[cf] += ",{0}{1}_{2},+".format(ds, i, cf)
					command.extend(cdef.values())

			if view.period_type == "Month":
				command.append("CDEF:voltage_MIN_trend=voltage_MIN")
				command.append("CDEF:voltage_AVG_trend=voltage_AVERAGE")
				command.append("CDEF:voltage_MAX_trend=voltage_MAX")
				command.append("CDEF:voltage_MIN_good=voltage_MIN_trend,215.2,GE,voltage_MIN_trend,UNKN,IF")
				command.append("CDEF:voltage_MIN_bad=voltage_MIN_trend,215.2,GE,UNKN,voltage_MIN_trend,IF")
				command.append("CDEF:voltage_MAX_good=voltage_MAX_trend,254,LE,voltage_MAX_trend,UNKN,IF")
				command.append("CDEF:voltage_MAX_bad=voltage_MAX_trend,254,LE,UNKN,voltage_MAX_trend,IF")

				command.extend([
					"LINE1:voltage_MIN_trend#6CC600:Min OK",
					"LINE1:voltage_MIN_bad#FC00FC:Too Low",
					"LINE1:voltage_AVG_trend#6C3612:Voltage",
					"LINE1:voltage_MAX_trend#6CC600:Max OK",
					"LINE1:voltage_MAX_bad#FC00FC:Too High",
					"LINE1:voltage_AVG_trend#6C3612",
				])
			elif view.period_type == "Hour":
				command.append("CDEF:voltage_MINMAX=voltage_MAX,voltage_MIN,-")

				command.extend([
					"AREA:voltage_MIN#00000000",
					"AREA:voltage_MINMAX#6C3612:Voltage:STACK",
				])
			else:
				command.extend([
					"LINE1:voltage_AVERAGE#6C3612:Voltage",
				])

		try:
			buffer = tempfile.TemporaryFile(dir="/dev/shm")
			rrdtool.graph(["/dev/fd/{0}".format(buffer.fileno())] + command)
			self.data = buffer.read()
		except BaseException as e:
			raise Exception(e, " ".join([str(x) for x in command]))

	def output(self, f):
		f.write(self.data)

def application(environ, start_response):
	try:
		attrs = {}
		if config["type"] == "gas":
			attrs["units"] = "m³"
			attrs["format"] = "#,##0.00"
		elif config["type"] == "electricity":
			attrs["units"] = "kW⋅h"
			attrs["format"] = "#,##0.000"
		else:
			raise webob.exc.HTTPInternalServerError("Unknown type configured")

		req = webob.Request(environ)
		view = View(req)

		if view.output == "table":
			usage = Usage(view)

			res = webob.Response(content_type="application/xml")
			f = res.body_file
			doc = XMLGenerator(f, "UTF-8")
			doc.startDocument()
			f.write('<?xml-stylesheet type="text/xsl" href="/usage.xsl"?>\n'.encode("UTF-8"))
			doc.startElement(config["type"], attrs)
			usage.output(doc)
			if config.get("rrd"):
				doc.startElement("graph", { "uri": "/" + view.date + "/graph/load" })
				doc.endElement("graph")
				doc.startElement("graph", { "uri": "/" + view.date + "/graph/supply" })
				doc.endElement("graph")
			doc.endElement(config["type"])
		elif view.output == "graph":
			graph = Graph(view)

			res = webob.Response(content_type="image/png")
			graph.output(res.body_file)
		else:
			raise webob.exc.HTTPInternalServerError("Unknown view output")

		return res(environ, start_response)
	except webob.exc.HTTPException as e:
		return e(environ, start_response)
