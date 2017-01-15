<?hh // strict
namespace HHRx;
use HHRx\Collection\VectorW;
use HHRx\Collection\KeyedProducer;
use HHRx\Collection\AsyncKeyedIteratorWrapper;
class StreamFactory {
	private Vector<KeyedStream<mixed, mixed>> $bounded_streams = Vector{};
	public function __construct(private ?TotalAwaitable $total_awaitable = null) {}
	public function make<Tk, T>(AsyncKeyedIterator<Tk, T> $raw_producer): KeyedStream<Tk, T> {
		$stream = new KeyedStream(new KeyedProducer($raw_producer), $this);
		$producer_total_awaitable = $stream->run();
		if(!is_null($this->total_awaitable))
			$this->total_awaitable->add($producer_total_awaitable);
		else
			$this->total_awaitable = new TotalAwaitable($producer_total_awaitable);
		return $stream;
	}
	public function bounded_make<Tk, T>(AsyncKeyedIterator<Tk, T> $raw_producer): KeyedStream<Tk, T> {
		$stream = new KeyedStream(new KeyedProducer($raw_producer), $this);
		// $stream->end_on($this->total_awaitable->get_awaitable()); // bound with the future longest-running query
		$this->bounded_streams->add($stream);
		return $stream;
	}
	public function static_bounded_make<Tk, T>(AsyncKeyedIterator<Tk, T> $raw_producer): KeyedStream<Tk, T> {
		$stream = new KeyedStream(new KeyedProducer($raw_producer), $this);
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
	public function merge<Tx, Tr>(Iterable<KeyedStream<Tx, Tr>> $incoming): KeyedStream<Tx, Tr> {
		// sacrificing `map` here because KeyedContainerWrapper isn't instantiable
		$producers = Vector{};
		foreach($incoming as $substream) {
			$producers->add(clone $substream->get_producer());
		}
		return $this->make(AsyncPoll::producer($producers)); // consider self rather than static
	}
	public function just<Tx, Tv>(Awaitable<Tv> $incoming, ?Tx $key = null): KeyedStream<?Tx, Tv> {
		return $this->make(async {
			$resolved_incoming = await $incoming;
			yield $key => $resolved_incoming;
		}); // consider self rather than static
	}
	public function from<Tx, Tv>(KeyedIterable<Tx, Awaitable<Tv>> $incoming): KeyedStream<Tx, Tv> {
		return $this->make(async { 
			foreach($incoming as $k => $awaitable) {
				$resolved_awaitable = await $awaitable;
				yield $k => $resolved_awaitable;
			}
		}); // consider self rather than static
	}
}