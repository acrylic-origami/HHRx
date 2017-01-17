<?hh // strict
namespace HHRx;
use HHRx\Collection\Producer;
class StreamFactory {
	private Vector<Stream<mixed>> $bounded_streams = Vector{};
	public function __construct(private ?TotalAwaitable $total_awaitable = null) {}
	public function make<Tk, T>(AsyncIterator<T> $raw_producer): Stream<T> {
		$stream = new Stream(new Producer($raw_producer), $this);
		$producer_total_awaitable = $stream->run();
		if(!is_null($this->total_awaitable))
			$this->total_awaitable->add($producer_total_awaitable);
		else
			$this->total_awaitable = new TotalAwaitable($producer_total_awaitable);
		return $stream;
	}
	public function bounded_make<T>(AsyncIterator<T> $raw_producer): Stream<T> {
		$stream = new Stream(new Producer($raw_producer), $this);
		// $stream->end_on($this->total_awaitable->get_awaitable()); // bound with the future longest-running query
		$this->bounded_streams->add($stream);
		return $stream;
	}
	public function static_bounded_make<T>(AsyncIterator<T> $raw_producer): Stream<T> {
		$stream = new Stream(new Producer($raw_producer), $this);
		if(!is_null($this->total_awaitable))
			$stream->end_on($this->total_awaitable->get_static_awaitable()); // bound with the current longest-running query. Note that this might still be unbounded if $this->total_awaitable is null
		return $stream;
	}
	public function get_total_awaitable(): Awaitable<void> { // not totally keen on this public getter
		// Let this be the only way to await all streams generated by this factory. Then we can safely defer bounded streams until here.
		$total_awaitable = $this->total_awaitable;
		if(!is_null($total_awaitable)) {
			foreach($this->bounded_streams as $bounded_stream)
				$bounded_stream->end_on($total_awaitable->get_awaitable()); // bound with the future longest-running query
			return $total_awaitable->get_awaitable();
		}
		elseif($this->bounded_streams->count() > 0)
			throw new \RuntimeException('Streams were bounded by the upper-bound Awaitable from this factory, but no stream was provided as an upper bound (e.g. `StreamFactory::make` was never called).');
		else
			return async {};
	}
	public function merge<Tr>(Iterable<Stream<Tr>> $incoming): Stream<Tr> {
		$producers = Vector{};
		foreach($incoming as $substream) {
			$producers->add($substream->clone_producer());
		}
		return $this->make(AsyncPoll::producer($producers));
	}
	public function concat<Tv>(Iterable<Stream<Tv>> $incoming): Stream<Tv> {
		return $this->make(async {
			$producers = $incoming->map((Stream<Tv> $stream) ==> $stream->clone_producer());
			foreach($producers as $producer)
				foreach($producer await as $v)
					yield $v;
		});
	}
	public function just<Tv>(Awaitable<Tv> $incoming): Stream<Tv> {
		return $this->make(async {
			$resolved_incoming = await $incoming;
			yield $resolved_incoming;
		});
	}
	public function from<Tv>(Iterable<Awaitable<Tv>> $incoming): Stream<Tv> {
		return $this->make(async { 
			foreach($incoming as $awaitable) {
				$resolved_awaitable = await $awaitable;
				yield $resolved_awaitable;
			}
		});
	}
	public function tick(int $period): Stream<int> {
		$stream = $this->make(async {
			for($i = 0; ; $i++) {
				await \HH\Asio\usleep($period);
				yield $i;
			}
		});
		$stream->end_on($this->get_total_awaitable());
		return $stream;
	}
}