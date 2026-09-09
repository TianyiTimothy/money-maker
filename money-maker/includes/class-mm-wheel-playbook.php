<?php
/**
 * Wheel-strategy playbook (Milestone 4g).
 *
 * The written half of the old standalone Wheel Tracker: 83 rules the user wrote
 * for themselves, in thirteen sections, plus the quick-reference table. It is
 * carried over verbatim because it is the part of that plugin the Questrade
 * feed cannot regenerate — the numbers can be derived, the discipline cannot.
 *
 * Content only: no hooks, no state, no register(). Rendered by MM_Admin on the
 * Wheels screen. Strings are wrapped for translation at the call site rather
 * than here, so the array stays a plain data table.
 *
 * @package MoneyMaker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static content for the wheel playbook.
 */
final class MM_Wheel_Playbook {

	/**
	 * The rule sections, in reading order.
	 *
	 * @return array<int,array{title:string,rules:string[]}>
	 */
	public static function sections(): array {
		return array(
			array(
				'title' => __( 'Before You Sell Anything', 'money-maker' ),
				'rules' => array(
					__( 'The wheel is not a way to make money on a stock you do not want. It is a way to get paid while you wait to buy something you do want, and paid again while you wait to sell it. If the stock is not one you would hold, no premium is large enough.', 'money-maker' ),
					__( 'Know what the maximum loss looks like before you start. A cash-secured put on a $180 stock risks $18,000 going to nearly zero, and collects a few hundred dollars for taking that risk. That trade-off is the whole strategy, and it only works on companies that do not go to zero.', 'money-maker' ),
					__( 'Only sell puts against cash you actually have set aside. If assignment would force you to sell something else or draw on margin, the position is bigger than your account.', 'money-maker' ),
					__( 'Decide which account this runs in before the first trade. Registered and non-registered accounts in Canada treat options and dividends differently, and some registered accounts restrict which option strategies are permitted at all. Confirm with your broker and, for the tax side, with an accountant — this tracker measures cash, not tax.', 'money-maker' ),
					__( 'Write down, in the wheel notes, the price you would be happy to own the stock at and the price you would be happy to sell it at. Every later decision gets checked against those two numbers.', 'money-maker' ),
					__( 'Expect the wheel to underperform simply holding the stock in a strong bull market. You are trading upside for income. Being at peace with that in advance is what stops you from chasing.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Choosing What to Wheel', 'money-maker' ),
				'rules' => array(
					__( 'Would you hold this company for six months if you were assigned tomorrow and the price kept falling? If the honest answer is no, do not sell the put.', 'money-maker' ),
					__( 'Prefer implied volatility rank above 30. Implied volatility rank compares today\'s implied volatility to its own past year, so it tells you whether options are expensive relative to how this stock normally trades. Low rank means you are taking real risk for very little premium.', 'money-maker' ),
					__( 'Check the option chain for liquidity before you check the premium. Wide gaps between the bid and the ask cost you on the way in and again on every roll and close. Open interest in the hundreds and a spread of a few cents is what you want.', 'money-maker' ),
					__( 'Avoid holding a short put through an earnings announcement unless you specifically want to be assigned at a lower price. The premium looks generous because the risk genuinely is.', 'money-maker' ),
					__( 'Keep no more than two concurrent wheels in the same sector. Correlated positions all get assigned in the same week, which is exactly when your cash is most stretched.', 'money-maker' ),
					__( 'Avoid stocks in the middle of a takeover, a restructuring, or a going-concern warning. Option pricing on those is unusual for reasons that have nothing to do with the premium being good value.', 'money-maker' ),
					__( 'A stock in a steady downtrend will hand you assignment after assignment at ever-worse prices. High premium is often the market correctly pricing bad news.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Selling Cash-Secured Puts', 'money-maker' ),
				'rules' => array(
					__( 'Sell 30 to 45 days to expiration. Time decay accelerates in the final month, so this range captures most of the decay without committing you so far out that the thesis can change underneath you.', 'money-maker' ),
					__( 'Target a delta of roughly -0.20 to -0.30. Delta approximates the probability the option finishes in the money, so this range means roughly a 70 to 80 percent chance of the put expiring worthless.', 'money-maker' ),
					__( 'Aim for at least 0.5 percent of the strike price in premium per cycle, after commission. On a $150 strike that means about $75 per contract net. Below that, the commission and the risk are not being paid for.', 'money-maker' ),
					__( 'Always use limit orders. A market order on an option with a wide spread can cost more than the trade earns.', 'money-maker' ),
					__( 'Record the actual fill price, not the mid-price you hoped for. The tracker is only as honest as the numbers you feed it.', 'money-maker' ),
					__( 'Sell the put with a price in mind, not a premium in mind. Choose the strike you want to own the stock at, then check whether the premium is worth it — never the other way round.', 'money-maker' ),
					__( 'One contract at a time until you have run several wheels start to finish. Scale comes after the process is proven, not before.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Managing an Open Put', 'money-maker' ),
				'rules' => array(
					__( 'Close early when about half the premium is gone and more than 21 days remain. The remaining premium is no longer paying you enough per day to justify holding the risk.', 'money-maker' ),
					__( 'Roll out and down when you can do it for a net credit: same or later expiry, lower strike. That lowers the price you would be assigned at and pushes the decision further out, and you are paid to do it.', 'money-maker' ),
					__( 'Do not pay a net debit larger than about half the premium you originally collected. Past that you are spending real money to avoid admitting a losing trade.', 'money-maker' ),
					__( 'When the put is deep in the money and assignment looks certain, compare taking assignment against rolling. If you still want the shares, assignment is often the cleaner and cheaper outcome, and it starts the covered-call side of the wheel.', 'money-maker' ),
					__( 'Record the exact net credit or debit of every roll. It flows straight into your break-even price, and a guess here corrupts every number after it.', 'money-maker' ),
					__( 'Never roll purely to avoid realizing a loss. Roll because the position is better afterwards. Those are different reasons that produce the same trade, and only one of them is a decision.', 'money-maker' ),
					__( 'Watch for early assignment on puts when the stock drops sharply or a large dividend is coming. American-style options can be exercised at any time, so assignment is not confined to expiry day.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'When You Get Assigned', 'money-maker' ),
				'rules' => array(
					__( 'Assignment is the plan working, not the plan failing. You bought a stock you wanted at a price you chose, with the premium reducing what you paid.', 'money-maker' ),
					__( 'Record the assignment the same day, including the assignment fee. The break-even price the tracker shows becomes the reference point for every call you sell afterwards.', 'money-maker' ),
					__( 'Start selling covered calls within a day or two. Shares sitting uncovered earn nothing while your capital is fully committed.', 'money-maker' ),
					__( 'Do not panic-sell into the drop. You accepted this outcome when you sold the put. Selling immediately at a loss converts a planned entry into an unplanned one.', 'money-maker' ),
					__( 'Do reassess if the reason for the drop is fundamental rather than market noise. A broken thesis is a reason to exit; a red week is not.', 'money-maker' ),
					__( 'If you were assigned early and the stock is about to go ex-dividend, check whether you now qualify for the dividend. Sometimes early assignment works in your favour.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Selling Covered Calls', 'money-maker' ),
				'rules' => array(
					__( 'Sell the call above your break-even price. Below it, being called away locks in a loss no matter how good the premium looked. The tracker warns you when a strike breaks this rule.', 'money-maker' ),
					__( 'The one exception is a deliberate exit: taking a small, known loss to free capital that is doing better work elsewhere. Make that a decision you write down, not a habit.', 'money-maker' ),
					__( 'Target a delta of roughly +0.20 to +0.30 and 30 to 45 days to expiration — the same framework as the put side. Consistency is what makes results comparable between wheels.', 'money-maker' ),
					__( 'Aim for at least 0.5 percent of your break-even price in premium per cycle, after commission.', 'money-maker' ),
					__( 'Sell only against shares you own, one contract per hundred shares. The tracker flags it when the call count exceeds your share count, because that is a naked call with a very different risk profile.', 'money-maker' ),
					__( 'If the stock runs past your strike, that is a completed wheel, not a mistake. You made the maximum this position was designed to make. Chasing the price with debit rolls is how a winning trade turns into a losing one.', 'money-maker' ),
					__( 'On a stock you are no longer keen on, sell the call closer to the money. You get more premium and a higher chance of being taken out of the position, which is what you want anyway.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Managing an Open Call', 'money-maker' ),
				'rules' => array(
					__( 'Close early when about half the premium is gone and more than 21 days remain, exactly as on the put side.', 'money-maker' ),
					__( 'Roll out and up for a net credit when you want to keep the shares: same or later expiry, higher strike. That raises your eventual sale price and pays you for the wait.', 'money-maker' ),
					__( 'Do not roll a call for a net debit just to keep shares that have already run past the strike. You are buying back upside you already sold, at a price set by the market that proved you wrong.', 'money-maker' ),
					__( 'When the stock is at or above the strike near expiry, letting the shares go is usually the right answer. A finished wheel is realized profit and free capital, which is the whole point.', 'money-maker' ),
					__( 'If you need the shares back for any reason — a dividend, an outright sale, a changed view — buy the call back first. Selling shares out from under a live short call leaves you naked.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Dividends and Early Assignment', 'money-maker' ),
				'rules' => array(
					__( 'Record every dividend. It reduces your break-even price by exactly as much as an equivalent amount of premium, and leaving it out understates the wheel.', 'money-maker' ),
					__( 'You only receive a dividend if you own the shares before the ex-dividend date. Being assigned the day after does not qualify you.', 'money-maker' ),
					__( 'Short calls are at their highest risk of early assignment in the day or two before an ex-dividend date, especially when the call is in the money and the remaining time value is less than the dividend. The holder exercises to capture the dividend.', 'money-maker' ),
					__( 'Before each ex-dividend date, look at any in-the-money short call and decide deliberately: keep the premium and risk losing the dividend, or buy the call back to protect it. Either is fine; being surprised is not.', 'money-maker' ),
					__( 'Do not chase a dividend by buying the stock just before the ex-date. The price typically drops by roughly the dividend amount on the ex-date, so there is no free money to collect.', 'money-maker' ),
					__( 'A dividend cut on a stock you are wheeling is a fundamental signal, not a rounding error. Reassess the position rather than continuing on autopilot.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Commissions and Costs', 'money-maker' ),
				'rules' => array(
					__( 'Enter the commission on every single transaction. A flat charge plus a per-contract charge on a one-contract trade can eat a meaningful slice of a $75 premium, and four of those per wheel is real money.', 'money-maker' ),
					__( 'Rolls cost two commissions, not one, because the broker fills two legs. The tracker pre-fills double for rolls — leave it unless your statement says otherwise.', 'money-maker' ),
					__( 'Assignment and exercise usually carry their own separate fee, larger than a normal trade commission. Include it, because it hits at the moment your capital is fully committed.', 'money-maker' ),
					__( 'Let an out-of-the-money option expire worthless rather than paying to close it for a few cents. That saves a commission for no added risk — but only when it is genuinely far out of the money and near expiry.', 'money-maker' ),
					__( 'Compare the premium to the commission before you enter. If commissions are more than about 10 percent of the expected premium, the trade is too small to be worth doing.', 'money-maker' ),
					__( 'Costs are why the small-premium, low-volatility trades that look safe often are not worth it. The risk is real and the net income after costs is not.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Walking Away Mid-Wheel', 'money-maker' ),
				'rules' => array(
					__( 'You are allowed to stop at any point. Buy the option back to close, and the position is over — record it as a buy-to-close and the ledger settles automatically.', 'money-maker' ),
					__( 'Good reasons to abandon a wheel: the investment case broke, you need the capital elsewhere, the position has grown too large for your account, or you no longer want to own the stock at any price near the strike.', 'money-maker' ),
					__( 'A bad reason: the position is uncomfortable but nothing has actually changed. Discomfort is what you were paid for.', 'money-maker' ),
					__( 'With shares held and a call open, exit in order — buy the call back first, then sell the shares. Doing it the other way round leaves you briefly holding a naked short call.', 'money-maker' ),
					__( 'Exiting at a loss is a legitimate outcome, not a failure of the strategy. Record it accurately. A wheel history with no losses in it is a history you cannot learn anything from.', 'money-maker' ),
					__( 'Once the position is flat, mark the wheel closed so the result is locked in and stops mixing with your open positions on the dashboard.', 'money-maker' ),
					__( 'Set your abandon condition before you enter, not during the drawdown. "I close this if the company cuts guidance" is a rule; "I close this because I feel sick about it" is a mood.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Measuring the Result', 'money-maker' ),
				'rules' => array(
					__( 'Profit and loss on a wheel is simply the sum of every cash movement: premiums in, buybacks out, the cost of the shares, the proceeds when they leave, dividends in, commissions out. If your ledger and your broker disagree, the ledger is missing a transaction.', 'money-maker' ),
					__( 'Judge every wheel on return against the capital it tied up, not on the dollar figure. Six hundred dollars on $18,000 held for two months is a very different trade from the same six hundred on $6,000 held for two weeks.', 'money-maker' ),
					__( 'Annualizing a short holding period exaggerates. A 2 percent return over three weeks annualizes to something enormous that you will never repeat consistently. Treat the annualized figure as a comparison tool between your own wheels, not a forecast.', 'money-maker' ),
					__( 'Compare each finished wheel against simply having bought and held the same stock over the same period. Sometimes the honest answer is that the wheel cost you money, and that is worth knowing.', 'money-maker' ),
					__( 'Include the losing wheels in every average you compute. Reviewing only the completed profitable ones is how people conclude a strategy never loses.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Risk and Position Sizing', 'money-maker' ),
				'rules' => array(
					__( 'Cap any single wheel at 5 to 10 percent of the portfolio, measured by the full assignment cost, not by the premium collected.', 'money-maker' ),
					__( 'Keep at least half your intended wheel capital uncommitted. Selling puts with no room to absorb assignment is how a manageable position becomes a forced sale.', 'money-maker' ),
					__( 'Five or six concurrent wheels is enough for most accounts. Beyond that, each one gets less attention exactly when attention matters.', 'money-maker' ),
					__( 'Set the maximum loss you will accept on a wheel before you open it, and act on it when it arrives.', 'money-maker' ),
					__( 'Never wheel with money you need within the next year. Assignment can leave you holding stock for months, and the timing is not yours to choose.', 'money-maker' ),
					__( 'Margin turns a defined-risk strategy into an undefined one. Cash-secured means cash-secured.', 'money-maker' ),
					__( 'Track total capital committed across all open wheels, not just each one individually. Six positions at 8 percent each is half your portfolio in one strategy.', 'money-maker' ),
				),
			),
			array(
				'title' => __( 'Record Keeping and Review', 'money-maker' ),
				'rules' => array(
					__( 'Record every transaction the day it fills, with the real fill price and the real commission. Reconstructing from memory a month later produces numbers that are confidently wrong.', 'money-maker' ),
					__( 'Reconcile against your broker statement monthly. The tracker should agree to the cent; if it does not, find the missing transaction rather than adjusting the total.', 'money-maker' ),
					__( 'Write a one-line note on every transaction explaining why. In three months the number will still be there but the reasoning will not.', 'money-maker' ),
					__( 'Download a backup periodically. The data lives in your own database, which is safer than a browser but is still one thing that can fail.', 'money-maker' ),
					__( 'Review completed wheels monthly and look for the pattern: which tickers, which deltas, which days to expiration actually worked for you. Your own history beats any general rule.', 'money-maker' ),
					__( 'Read the relevant section here before opening a position, not after it goes wrong. One avoided bad trade pays for every minute spent on the discipline.', 'money-maker' ),
					__( 'Nothing in this list is financial advice. It is a checklist for following your own plan consistently, which is a different thing from the plan being right.', 'money-maker' ),
				),
			),
		);
	}

	/**
	 * The quick-reference table: the numbers worth checking before a trade.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function quick_reference(): array {
		return array(
			array( __( 'Days to expiration', 'money-maker' ), __( '30 to 45', 'money-maker' ) ),
			array( __( 'Put delta', 'money-maker' ), __( '-0.20 to -0.30', 'money-maker' ) ),
			array( __( 'Call delta', 'money-maker' ), __( '+0.20 to +0.30', 'money-maker' ) ),
			array( __( 'Close or roll when', 'money-maker' ), __( 'Half the premium is gone and over 21 days remain', 'money-maker' ) ),
			array( __( 'Minimum premium', 'money-maker' ), __( '0.5% of strike or break-even, after commission', 'money-maker' ) ),
			array( __( 'Maximum per wheel', 'money-maker' ), __( '5 to 10% of the portfolio', 'money-maker' ) ),
			array( __( 'Covered call floor', 'money-maker' ), __( 'Never below your break-even price', 'money-maker' ) ),
			array( __( 'Before an ex-dividend date', 'money-maker' ), __( 'Check any in-the-money short call', 'money-maker' ) ),
		);
	}
}
